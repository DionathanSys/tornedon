<?php

namespace App\Console\Commands;

use App\Services\DatabaseBackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class BackupDatabaseCommand extends Command
{
    protected $signature = 'backup:database
        {--connection= : Nome da conexão configurada em config/database.php}
        {--keep-days= : Sobrescreve a retenção configurada para esta execução}
        {--dry-run : Apenas valida a configuração e mostra o destino do backup}';

    protected $description = 'Gera um backup do banco de dados configurado para a aplicação.';

    public function handle(DatabaseBackupService $backupService): int
    {
        $connection = $this->option('connection');
        $keepDays = $this->option('keep-days');

        if ($keepDays !== null && (! is_numeric($keepDays) || (int) $keepDays < 0)) {
            $this->components->error('O valor de --keep-days deve ser um número inteiro maior ou igual a zero.');

            return self::INVALID;
        }

        if ($keepDays !== null) {
            config(['backup.database.keep_days' => (int) $keepDays]);
        }

        try {
            if ((bool) $this->option('dry-run')) {
                $plan = $backupService->plan($connection);

                $this->components->twoColumnDetail('Conexão', $plan['connection']);
                $this->components->twoColumnDetail('Driver', $plan['driver']);
                $this->components->twoColumnDetail('Banco', $plan['database']);
                $this->components->twoColumnDetail('Destino', $plan['final_path']);
                $this->components->twoColumnDetail('Compressão', $plan['should_compress'] ? 'sim' : 'não');
                $this->components->twoColumnDetail('Retenção', $plan['keep_days'].' dia(s)');

                if (is_string($plan['binary'])) {
                    $this->components->twoColumnDetail('Executável', $plan['binary']);
                }

                $this->components->info('Configuração validada com sucesso.');

                return self::SUCCESS;
            }

            $result = $backupService->run($connection);

            $this->components->info('Backup concluído com sucesso.');
            $this->components->twoColumnDetail('Arquivo', $result['file_path']);
            $this->components->twoColumnDetail('Arquivos removidos', (string) $result['deleted_files']);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            Log::error('backup:database falhou', [
                'connection' => $connection ?: config('database.default'),
                'message' => $exception->getMessage(),
            ]);

            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
