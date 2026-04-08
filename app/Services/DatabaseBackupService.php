<?php

namespace App\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

class DatabaseBackupService
{
    public function plan(?string $connectionName = null): array
    {
        $connectionName ??= config('backup.database.connection') ?: config('database.default');

        $connection = config("database.connections.{$connectionName}");

        if (! is_array($connection)) {
            throw new RuntimeException("A conexão de banco [{$connectionName}] não foi encontrada.");
        }

        $driver = (string) Arr::get($connection, 'driver', '');
        $directory = $this->resolveBackupDirectory();

        File::ensureDirectoryExists($directory);

        $databaseName = (string) Arr::get($connection, 'database', 'database');
        $timestamp = now()->format('Ymd_His');
        $baseFilename = $timestamp.'_'.Str::slug($connectionName, '_').'_'.Str::slug($databaseName, '_');

        $baseExtension = $driver === 'sqlite' ? 'sqlite' : 'sql';
        $rawPath = $directory.DIRECTORY_SEPARATOR.$baseFilename.'.'.$baseExtension;

        $shouldCompress = (bool) config('backup.database.compress', true)
            && $driver !== 'sqlite'
            && function_exists('gzopen');

        return [
            'connection' => $connectionName,
            'driver' => $driver,
            'database' => $databaseName,
            'host' => (string) Arr::get($connection, 'host', ''),
            'port' => (string) Arr::get($connection, 'port', ''),
            'username' => (string) Arr::get($connection, 'username', ''),
            'password' => (string) Arr::get($connection, 'password', ''),
            'socket' => (string) Arr::get($connection, 'unix_socket', ''),
            'charset' => (string) Arr::get($connection, 'charset', 'utf8mb4'),
            'source_path' => $driver === 'sqlite' ? $this->resolveSqliteSourcePath($databaseName) : null,
            'directory' => $directory,
            'raw_path' => $rawPath,
            'final_path' => $shouldCompress ? $rawPath.'.gz' : $rawPath,
            'should_compress' => $shouldCompress,
            'keep_days' => max(0, (int) config('backup.database.keep_days', 14)),
            'timeout' => max(60, (int) config('backup.database.timeout', 600)),
            'binary' => in_array($driver, ['mysql', 'mariadb'], true) ? $this->resolveDumpBinary() : null,
        ];
    }

    public function run(?string $connectionName = null): array
    {
        $plan = $this->plan($connectionName);

        match ($plan['driver']) {
            'mysql', 'mariadb' => $this->backupMysql($plan),
            'sqlite' => $this->backupSqlite($plan),
            default => throw new RuntimeException("O driver [{$plan['driver']}] não é suportado pela rotina de backup."),
        };

        $deletedFiles = $this->cleanupOldBackups($plan['directory'], $plan['keep_days']);

        return [
            'connection' => $plan['connection'],
            'driver' => $plan['driver'],
            'file_path' => $plan['final_path'],
            'deleted_files' => $deletedFiles,
            'compressed' => $plan['should_compress'],
        ];
    }

    private function backupMysql(array $plan): void
    {
        File::ensureDirectoryExists($plan['directory']);
        $targetPath = $plan['should_compress'] ? $plan['final_path'] : $plan['raw_path'];

        $command = [
            $plan['binary'],
            '--single-transaction',
            '--quick',
            '--skip-lock-tables',
            '--hex-blob',
            '--routines',
            '--triggers',
            '--events',
            '--default-character-set='.$plan['charset'],
            '--user='.$plan['username'],
        ];

        if ($plan['socket'] !== '') {
            $command[] = '--socket='.$plan['socket'];
        } else {
            $command[] = '--protocol=TCP';
            $command[] = '--host='.$plan['host'];
            $command[] = '--port='.$plan['port'];
        }

        $command[] = $plan['database'];

        $environment = [];

        if ($plan['password'] !== '') {
            $environment['MYSQL_PWD'] = $plan['password'];
        }

        $handle = $this->openBackupHandle($targetPath, $plan['should_compress']);

        try {
            $process = new Process($command, base_path(), $environment, null, $plan['timeout']);
            $process->run(function (string $type, string $buffer) use ($handle, $plan): void {
                if ($type !== Process::OUT) {
                    return;
                }

                $this->writeBackupChunk($handle, $buffer, $plan['should_compress']);
            });
        } catch (Throwable $exception) {
            $this->closeBackupHandle($handle, $plan['should_compress']);
            File::delete($targetPath);

            throw $exception;
        }

        $this->closeBackupHandle($handle, $plan['should_compress']);

        if (! $process->isSuccessful()) {
            File::delete($targetPath);

            throw new RuntimeException(
                'Falha ao executar o dump do banco: '.trim($process->getErrorOutput() ?: $process->getOutput())
            );
        }
    }

    private function backupSqlite(array $plan): void
    {
        $sourcePath = $plan['source_path'];

        if (! is_string($sourcePath) || ! File::exists($sourcePath)) {
            throw new RuntimeException('O arquivo do banco SQLite não foi encontrado para backup.');
        }

        File::copy($sourcePath, $plan['raw_path']);
    }

    private function openBackupHandle(string $targetPath, bool $compressed)
    {
        $handle = $compressed ? gzopen($targetPath, 'wb9') : fopen($targetPath, 'wb');

        if ($handle === false) {
            throw new RuntimeException("Não foi possível abrir o arquivo de backup [{$targetPath}] para escrita.");
        }

        return $handle;
    }

    private function writeBackupChunk($handle, string $buffer, bool $compressed): void
    {
        $written = $compressed
            ? gzwrite($handle, $buffer)
            : fwrite($handle, $buffer);

        if ($written === false) {
            throw new RuntimeException('Falha ao gravar o conteúdo do backup em disco.');
        }
    }

    private function cleanupOldBackups(string $directory, int $keepDays): int
    {
        if ($keepDays <= 0 || ! File::isDirectory($directory)) {
            return 0;
        }

        $deletedFiles = 0;
        $cutoff = now()->subDays($keepDays)->getTimestamp();

        foreach (File::files($directory) as $file) {
            if ($file->getMTime() >= $cutoff) {
                continue;
            }

            File::delete($file->getPathname());
            $deletedFiles++;
        }

        return $deletedFiles;
    }

    private function closeBackupHandle($handle, bool $compressed): void
    {
        if ($handle === null || $handle === false) {
            return;
        }

        if ($compressed) {
            gzclose($handle);
        } else {
            fclose($handle);
        }
    }

    private function resolveBackupDirectory(): string
    {
        $configuredDirectory = (string) config('backup.database.directory', 'app/backups/database');

        if ($configuredDirectory === '') {
            return storage_path('app/backups/database');
        }

        if ($this->isAbsolutePath($configuredDirectory)) {
            return $configuredDirectory;
        }

        return storage_path($configuredDirectory);
    }

    private function resolveSqliteSourcePath(string $database): string
    {
        return $this->isAbsolutePath($database)
            ? $database
            : database_path($database);
    }

    private function resolveDumpBinary(): string
    {
        $configuredBinary = (string) config('backup.database.binary', '');

        if ($configuredBinary !== '' && File::exists($configuredBinary)) {
            return $configuredBinary;
        }

        $finder = new ExecutableFinder;
        $fromPath = $finder->find('mysqldump') ?? $finder->find('mariadb-dump');

        if (is_string($fromPath) && $fromPath !== '') {
            return $fromPath;
        }

        foreach ([
            'C:\\laragon\\bin\\mysql\\*\\bin\\mysqldump.exe',
            'C:\\laragon\\bin\\mysql\\*\\bin\\mariadb-dump.exe',
        ] as $pattern) {
            $matches = glob($pattern);

            if ($matches !== false && $matches !== []) {
                return (string) $matches[0];
            }
        }

        throw new RuntimeException(
            'Não foi possível localizar o executável de dump do MySQL/MariaDB. Configure BACKUP_DB_BINARY no .env.'
        );
    }

    private function isAbsolutePath(string $path): bool
    {
        return Str::startsWith($path, ['/', '\\']) || (bool) preg_match('/^[A-Za-z]:[\\\\\\/]/', $path);
    }
}
