<?php

namespace Tests\Feature\Console;

use App\Mail\DatabaseBackupFailedMail;
use App\Services\DatabaseBackupService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class BackupDatabaseCommandTest extends TestCase
{
    /** @var string[] */
    private array $temporaryDirectories = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryDirectories as $directory) {
            File::deleteDirectory($directory);
        }

        parent::tearDown();
    }

    public function test_sqlite_backup_is_promoted_without_leaving_part_files(): void
    {
        [$directory, $sourcePath] = $this->configureSqliteBackup();

        file_put_contents($sourcePath, 'sqlite-backup-content');

        $result = app(DatabaseBackupService::class)->run('backup_test_sqlite');

        $this->assertFileExists($result['file_path']);
        $this->assertSame('sqlite-backup-content', file_get_contents($result['file_path']));
        $this->assertSame([], glob($directory.'/.database_backup_*.part'));
    }

    public function test_mysql_backup_uses_result_file_and_avoids_tablespace_privilege_requirement(): void
    {
        [$directory] = $this->configureMysqlBackup();
        $scriptPath = $directory.'/fake-mysqldump.sh';
        $argumentsPath = $directory.'/arguments.log';

        file_put_contents($scriptPath, <<<'SH'
#!/bin/sh
result_file=""
for argument in "$@"; do
    case "$argument" in
        --result-file=*) result_file="${argument#--result-file=}" ;;
    esac
done
printf '%s\n' "$@" > "__ARGUMENTS_PATH__"
if [ -z "$result_file" ]; then
    exit 2
fi
printf '%s' 'CREATE TABLE backup_test (id INT);' > "$result_file"
SH
        );
        file_put_contents(
            $scriptPath,
            str_replace('__ARGUMENTS_PATH__', $argumentsPath, (string) file_get_contents($scriptPath))
        );
        chmod($scriptPath, 0755);

        config()->set('backup.database.binary', $scriptPath);

        $result = app(DatabaseBackupService::class)->run('backup_test_mysql');
        $arguments = (string) file_get_contents($argumentsPath);

        $this->assertFileExists($result['file_path']);
        $this->assertSame(
            'CREATE TABLE backup_test (id INT);',
            gzdecode((string) file_get_contents($result['file_path']))
        );
        $this->assertStringContainsString('--result-file=', $arguments);
        $this->assertStringContainsString('--no-tablespaces', $arguments);
        $this->assertSame([], glob($directory.'/.database_backup_*.part'));
    }

    public function test_failed_mysql_dump_does_not_leave_partial_artifacts(): void
    {
        [$directory] = $this->configureMysqlBackup();
        $scriptPath = $directory.'/failing-mysqldump.sh';

        file_put_contents($scriptPath, <<<'SH'
#!/bin/sh
for argument in "$@"; do
    case "$argument" in
        --result-file=*) result_file="${argument#--result-file=}" ;;
    esac
done
printf '%s' 'partial dump' > "$result_file"
exit 1
SH
        );
        chmod($scriptPath, 0755);
        config()->set('backup.database.binary', $scriptPath);

        $this->expectException(RuntimeException::class);

        try {
            app(DatabaseBackupService::class)->run('backup_test_mysql');
        } finally {
            $this->assertSame([], glob($directory.'/.database_backup_*.part'));
            $this->assertSame([], glob($directory.'/database_backup_*'));
        }
    }

    public function test_retention_removes_only_expired_managed_backups(): void
    {
        [$directory, $sourcePath] = $this->configureSqliteBackup();

        file_put_contents($sourcePath, 'sqlite-backup-content');

        $expiredManagedBackup = $directory.'/database_backup_old.sql';
        $expiredUnmanagedBackup = $directory.'/old-not-a-backup.sql';
        $expiredTextFile = $directory.'/database_backup_notes.txt';

        file_put_contents($expiredManagedBackup, 'old');
        file_put_contents($expiredUnmanagedBackup, 'old');
        file_put_contents($expiredTextFile, 'old');

        $expiredTimestamp = now()->subDays(8)->getTimestamp();
        touch($expiredManagedBackup, $expiredTimestamp);
        touch($expiredUnmanagedBackup, $expiredTimestamp);
        touch($expiredTextFile, $expiredTimestamp);

        app(DatabaseBackupService::class)->run('backup_test_sqlite');

        $this->assertFileDoesNotExist($expiredManagedBackup);
        $this->assertFileExists($expiredUnmanagedBackup);
        $this->assertFileExists($expiredTextFile);
    }

    public function test_backup_can_be_uploaded_to_r2_and_removed_from_local_staging(): void
    {
        [, $sourcePath] = $this->configureSqliteBackup();
        Storage::fake('r2');
        config()->set('backup.database.disk', 'r2');
        config()->set('backup.database.path', 'backups/database');

        file_put_contents($sourcePath, 'sqlite-backup-content');

        $result = app(DatabaseBackupService::class)->run('backup_test_sqlite');

        $this->assertTrue(Storage::disk('r2')->exists($result['storage_path']));
        $this->assertStringStartsWith('r2://backups/database/', $result['file_path']);
        $this->assertNull($result['local_file_path']);
    }

    public function test_command_sends_an_alert_when_backup_fails(): void
    {
        Mail::fake();
        config()->set('backup.database.alert_email', 'admin@example.com');

        $this->mock(DatabaseBackupService::class, function ($mock): void {
            $mock->shouldReceive('run')
                ->once()
                ->andThrow(new RuntimeException('Falha simulada no backup.'));
        });

        $this->artisan('backup:database')
            ->assertExitCode(1);

        Mail::assertSent(DatabaseBackupFailedMail::class, function (DatabaseBackupFailedMail $mail): bool {
            return $mail->connectionName === 'sqlite'
                && $mail->failureMessage === 'Falha simulada no backup.';
        });

        $mail = new DatabaseBackupFailedMail('sqlite', 'Falha simulada no backup.', '2026-09-29 02:00:00');

        $this->assertStringContainsString('Falha simulada no backup.', $mail->render());
    }

    /** @return array{0: string, 1: string} */
    private function configureSqliteBackup(): array
    {
        $root = $this->createTemporaryDirectory();
        $directory = $root.'/backups';
        $sourcePath = $root.'/database.sqlite';

        mkdir($directory, 0775, true);

        config()->set('database.connections.backup_test_sqlite', [
            'driver' => 'sqlite',
            'database' => $sourcePath,
        ]);
        config()->set('backup.database.directory', $directory);
        config()->set('backup.database.disk', '');
        config()->set('backup.database.compress', true);
        config()->set('backup.database.file_prefix', 'database_backup');
        config()->set('backup.database.keep_days', 7);

        return [$directory, $sourcePath];
    }

    /** @return array{0: string} */
    private function configureMysqlBackup(): array
    {
        $root = $this->createTemporaryDirectory();
        $directory = $root.'/backups';

        mkdir($directory, 0775, true);

        config()->set('database.connections.backup_test_mysql', [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => '3306',
            'database' => 'backup_test',
            'username' => 'backup',
            'password' => 'secret',
            'charset' => 'utf8mb4',
        ]);
        config()->set('backup.database.directory', $directory);
        config()->set('backup.database.disk', '');
        config()->set('backup.database.compress', true);
        config()->set('backup.database.file_prefix', 'database_backup');
        config()->set('backup.database.keep_days', 7);

        return [$directory];
    }

    private function createTemporaryDirectory(): string
    {
        $directory = sys_get_temp_dir().'/tornedon-backup-'.bin2hex(random_bytes(8));
        mkdir($directory, 0775, true);
        $this->temporaryDirectories[] = $directory;

        return $directory;
    }
}
