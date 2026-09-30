<?php

namespace App\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
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
        $databaseName = (string) Arr::get($connection, 'database', 'database');
        $timestamp = now()->format('Ymd_His_u');
        $filenamePrefix = $this->resolveFilenamePrefix();
        $baseFilename = $filenamePrefix.'_'.$timestamp.'_'.Str::slug($connectionName, '_').'_'.Str::slug($databaseName, '_');
        $baseExtension = $driver === 'sqlite' ? 'sqlite' : 'sql';
        $rawFilename = $baseFilename.'.'.$baseExtension;
        $shouldCompress = (bool) config('backup.database.compress', true)
            && $driver !== 'sqlite'
            && function_exists('gzopen')
            && function_exists('gzwrite');
        $finalFilename = $shouldCompress ? $rawFilename.'.gz' : $rawFilename;
        $rawPath = $directory.DIRECTORY_SEPARATOR.$rawFilename;
        $finalPath = $directory.DIRECTORY_SEPARATOR.$finalFilename;
        $temporaryRawPath = $directory.DIRECTORY_SEPARATOR.'.'.$rawFilename.'.part';
        $temporaryFinalPath = $directory.DIRECTORY_SEPARATOR.'.'.$finalFilename.'.part';
        $disk = trim((string) config('backup.database.disk', ''));
        $storagePrefix = trim((string) config('backup.database.path', 'backups/database'), '/');
        $storagePath = $storagePrefix === '' ? $finalFilename : $storagePrefix.'/'.$finalFilename;

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
            'filename_prefix' => $filenamePrefix,
            'raw_path' => $rawPath,
            'final_path' => $finalPath,
            'temporary_raw_path' => $temporaryRawPath,
            'temporary_final_path' => $temporaryFinalPath,
            'disk' => $disk,
            'storage_prefix' => $storagePrefix,
            'storage_path' => $storagePath,
            'should_compress' => $shouldCompress,
            'keep_days' => max(0, (int) config('backup.database.keep_days', 7)),
            'timeout' => max(60, (int) config('backup.database.timeout', 600)),
            'binary' => in_array($driver, ['mysql', 'mariadb'], true) ? $this->resolveDumpBinary() : null,
        ];
    }

    public function run(?string $connectionName = null): array
    {
        $plan = $this->plan($connectionName);

        File::ensureDirectoryExists($plan['directory']);

        match ($plan['driver']) {
            'mysql', 'mariadb' => $this->backupMysql($plan),
            'sqlite' => $this->backupSqlite($plan),
            default => throw new RuntimeException("O driver [{$plan['driver']}] não é suportado pela rotina de backup."),
        };

        $storedPath = $this->publishBackup($plan);
        $deletedFiles = $this->cleanupOldBackups($plan);

        return [
            'connection' => $plan['connection'],
            'driver' => $plan['driver'],
            'file_path' => $storedPath,
            'local_file_path' => $plan['disk'] === '' ? $plan['final_path'] : null,
            'storage_disk' => $plan['disk'] !== '' ? $plan['disk'] : null,
            'storage_path' => $plan['disk'] !== '' ? $plan['storage_path'] : null,
            'deleted_files' => $deletedFiles,
            'compressed' => $plan['should_compress'],
        ];
    }

    private function backupMysql(array $plan): void
    {
        $this->removeTemporaryArtifacts($plan);

        $command = [
            $plan['binary'],
            '--result-file='.$plan['temporary_raw_path'],
            '--single-transaction',
            '--quick',
            '--skip-lock-tables',
            '--no-tablespaces',
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

        try {
            $process = new Process($command, base_path(), $environment, null, $plan['timeout']);
            $process->run();

            if (! $process->isSuccessful()) {
                throw new RuntimeException(
                    'Falha ao executar o dump do banco: '.trim($process->getErrorOutput() ?: $process->getOutput())
                );
            }

            $this->assertNonEmptyFile($plan['temporary_raw_path'], 'O dump do banco não gerou um arquivo válido.');

            if ($plan['should_compress']) {
                $this->compressFile($plan['temporary_raw_path'], $plan['temporary_final_path']);
                File::delete($plan['temporary_raw_path']);
                $this->moveAtomically($plan['temporary_final_path'], $plan['final_path']);

                return;
            }

            $this->moveAtomically($plan['temporary_raw_path'], $plan['final_path']);
        } catch (Throwable $exception) {
            $this->removeTemporaryArtifacts($plan);
            File::delete($plan['final_path']);

            throw $exception;
        }
    }

    private function backupSqlite(array $plan): void
    {
        $sourcePath = $plan['source_path'];

        if (! is_string($sourcePath) || ! File::exists($sourcePath)) {
            throw new RuntimeException('O arquivo do banco SQLite não foi encontrado para backup.');
        }

        $this->removeTemporaryArtifacts($plan);

        try {
            if (! File::copy($sourcePath, $plan['temporary_raw_path'])) {
                throw new RuntimeException('Não foi possível copiar o arquivo do banco SQLite.');
            }

            $this->assertNonEmptyFile($plan['temporary_raw_path'], 'A cópia do banco SQLite está vazia.');
            $this->moveAtomically($plan['temporary_raw_path'], $plan['final_path']);
        } catch (Throwable $exception) {
            $this->removeTemporaryArtifacts($plan);
            File::delete($plan['final_path']);

            throw $exception;
        }
    }

    private function publishBackup(array $plan): string
    {
        if ($plan['disk'] === '') {
            return $plan['final_path'];
        }

        $stream = fopen($plan['final_path'], 'rb');

        if ($stream === false) {
            throw new RuntimeException('Não foi possível abrir o backup para envio ao armazenamento remoto.');
        }

        try {
            $uploaded = Storage::disk($plan['disk'])->put($plan['storage_path'], $stream);
        } finally {
            fclose($stream);
        }

        if (! $uploaded) {
            throw new RuntimeException("Não foi possível enviar o backup para o disco [{$plan['disk']}].");
        }

        File::delete($plan['final_path']);

        return $plan['disk'].'://'.$plan['storage_path'];
    }

    private function cleanupOldBackups(array $plan): int
    {
        if ($plan['keep_days'] <= 0) {
            return 0;
        }

        $cutoff = now()->subDays($plan['keep_days'])->getTimestamp();

        if ($plan['disk'] !== '') {
            $deletedFiles = 0;
            $disk = Storage::disk($plan['disk']);

            foreach ($disk->files($plan['storage_prefix']) as $path) {
                if (! $this->isManagedBackupFilename(basename($path), $plan['filename_prefix'])) {
                    continue;
                }

                if ($disk->lastModified($path) >= $cutoff) {
                    continue;
                }

                if (! $disk->delete($path)) {
                    throw new RuntimeException("Não foi possível remover o backup expirado [{$path}].");
                }

                $deletedFiles++;
            }

            return $deletedFiles;
        }

        if (! File::isDirectory($plan['directory'])) {
            return 0;
        }

        $deletedFiles = 0;

        foreach (File::files($plan['directory']) as $file) {
            if (! $this->isManagedBackupFilename($file->getFilename(), $plan['filename_prefix'])) {
                continue;
            }

            if ($file->getMTime() >= $cutoff) {
                continue;
            }

            File::delete($file->getPathname());
            $deletedFiles++;
        }

        return $deletedFiles;
    }

    private function compressFile(string $sourcePath, string $targetPath): void
    {
        $input = fopen($sourcePath, 'rb');
        $output = gzopen($targetPath, 'wb9');

        if ($input === false || $output === false) {
            if (is_resource($input)) {
                fclose($input);
            }

            if ($output !== false) {
                gzclose($output);
            }

            throw new RuntimeException('Não foi possível preparar a compressão do backup.');
        }

        try {
            while (! feof($input)) {
                $buffer = fread($input, 1024 * 1024);

                if ($buffer === false) {
                    throw new RuntimeException('Falha ao ler o dump para compressão.');
                }

                if ($buffer === '') {
                    continue;
                }

                $this->writeCompressedChunk($output, $buffer);
            }

            if (! gzclose($output)) {
                $output = false;
                throw new RuntimeException('Falha ao finalizar a compressão do backup.');
            }

            $output = false;
        } catch (Throwable $exception) {
            if ($output !== false) {
                gzclose($output);
            }

            throw $exception;
        } finally {
            fclose($input);
        }
    }

    private function writeCompressedChunk($handle, string $buffer): void
    {
        $offset = 0;
        $length = strlen($buffer);

        while ($offset < $length) {
            $written = gzwrite($handle, substr($buffer, $offset));

            if ($written === false || $written === 0) {
                throw new RuntimeException('Falha ao gravar o backup compactado.');
            }

            $offset += $written;
        }
    }

    private function assertNonEmptyFile(string $path, string $message): void
    {
        if (! File::exists($path) || (int) File::size($path) === 0) {
            throw new RuntimeException($message);
        }
    }

    private function moveAtomically(string $sourcePath, string $targetPath): void
    {
        if (! @rename($sourcePath, $targetPath)) {
            throw new RuntimeException("Não foi possível finalizar o arquivo de backup [{$targetPath}].");
        }
    }

    private function removeTemporaryArtifacts(array $plan): void
    {
        File::delete([
            $plan['temporary_raw_path'],
            $plan['temporary_final_path'],
        ]);
    }

    private function isManagedBackupFilename(string $filename, string $prefix): bool
    {
        if (Str::startsWith($filename, $prefix.'_')) {
            return Str::endsWith($filename, ['.sql', '.sql.gz', '.sqlite']);
        }

        return (bool) preg_match(
            '/^\d{8}_\d{6}(?:_\d{6})?_[a-z0-9_-]+_[a-z0-9_-]+\.(?:sql|sqlite)(?:\.gz)?$/i',
            $filename
        );
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

    private function resolveFilenamePrefix(): string
    {
        $configuredPrefix = Str::slug((string) config('backup.database.file_prefix', 'database_backup'), '_');

        return $configuredPrefix !== '' ? $configuredPrefix : 'database_backup';
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
        return Str::startsWith($path, ['/', '\\']) || (bool) preg_match('/^[A-Za-z]:[\\\\\/]/', $path);
    }
}
