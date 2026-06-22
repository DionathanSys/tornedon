<?php

namespace App\Console\Commands;

use App\Services\TemporaryMigration\TemporaryServiceImportService;
use Illuminate\Console\Command;

class ImportTemporaryMigrationServicesCommand extends Command
{
    protected $signature = 'migration:services:import
        {company_id}
        {user_id}
        {--limit=500}
        {--after-id=0}
        {--updated-from=}
        {--include-deleted}
        {--ativo=}
        {--max-pages=0}';

    protected $description = 'Importa temporariamente servicos da API de migracao do sistema legado';

    public function handle(TemporaryServiceImportService $service): int
    {
        $result = $service->import((int) $this->argument('company_id'), (int) $this->argument('user_id'), [
            'limit' => (int) $this->option('limit'),
            'after_id' => (int) $this->option('after-id'),
            'updated_from' => $this->option('updated-from') ?: null,
            'include_deleted' => (bool) $this->option('include-deleted'),
            'ativo' => $this->option('ativo'),
            'max_pages' => (int) $this->option('max-pages'),
        ]);

        if ($result === null) {
            $this->error($service->getMessageUser() ?: 'Falha ao importar servicos.');
            return self::FAILURE;
        }

        $this->info($service->getMessage());
        $this->line('Paginas lidas: ' . $result['pages']);
        $this->line('Registros recebidos: ' . $result['records_received']);
        $this->line('Servicos criados: ' . $result['services_created']);
        $this->line('Servicos atualizados: ' . $result['services_updated']);

        return self::SUCCESS;
    }
}
