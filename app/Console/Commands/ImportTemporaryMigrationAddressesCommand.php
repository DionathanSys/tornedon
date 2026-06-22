<?php

namespace App\Console\Commands;

use App\Services\TemporaryMigration\TemporaryAddressImportService;
use Illuminate\Console\Command;

class ImportTemporaryMigrationAddressesCommand extends Command
{
    protected $signature = 'migration:addresses:import
        {company_id}
        {user_id}
        {--limit=500}
        {--after-id=0}
        {--updated-from=}
        {--parceiro-id=}
        {--max-pages=0}';

    protected $description = 'Importa temporariamente enderecos da API de migracao do sistema legado';

    public function handle(TemporaryAddressImportService $service): int
    {
        $result = $service->import((int) $this->argument('company_id'), (int) $this->argument('user_id'), [
            'limit' => (int) $this->option('limit'),
            'after_id' => (int) $this->option('after-id'),
            'updated_from' => $this->option('updated-from') ?: null,
            'parceiro_id' => $this->option('parceiro-id') !== null ? (int) $this->option('parceiro-id') : null,
            'max_pages' => (int) $this->option('max-pages'),
        ]);

        if ($result === null) {
            $this->error($service->getMessageUser() ?: 'Falha ao importar enderecos.');
            return self::FAILURE;
        }

        $this->info($service->getMessage());
        $this->line('Paginas lidas: ' . $result['pages']);
        $this->line('Registros recebidos: ' . $result['records_received']);
        $this->line('Enderecos criados: ' . $result['addresses_created']);
        $this->line('Enderecos atualizados: ' . $result['addresses_updated']);

        return self::SUCCESS;
    }
}
