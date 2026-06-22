<?php

namespace App\Console\Commands;

use App\Services\TemporaryMigration\TemporaryServiceOrderImportService;
use Illuminate\Console\Command;

class ImportTemporaryMigrationServiceOrdersCommand extends Command
{
    protected $signature = 'migration:service-orders:import
        {company_id}
        {user_id}
        {--limit=200}
        {--after-id=0}
        {--updated-from=}
        {--parceiro-id=}
        {--equipamento-id=}
        {--fatura-id=}
        {--status=}
        {--max-pages=0}';

    protected $description = 'Importa temporariamente ordens de servico e itens da API de migracao do sistema legado';

    public function handle(TemporaryServiceOrderImportService $service): int
    {
        $result = $service->import((int) $this->argument('company_id'), (int) $this->argument('user_id'), [
            'limit' => (int) $this->option('limit'),
            'after_id' => (int) $this->option('after-id'),
            'updated_from' => $this->option('updated-from') ?: null,
            'parceiro_id' => $this->option('parceiro-id') !== null ? (int) $this->option('parceiro-id') : null,
            'equipamento_id' => $this->option('equipamento-id') !== null ? (int) $this->option('equipamento-id') : null,
            'fatura_id' => $this->option('fatura-id') !== null ? (int) $this->option('fatura-id') : null,
            'status' => $this->option('status') ?: null,
            'max_pages' => (int) $this->option('max-pages'),
        ]);

        if ($result === null) {
            $this->error($service->getMessageUser() ?: 'Falha ao importar ordens de servico.');
            return self::FAILURE;
        }

        $this->info($service->getMessage());
        $this->line('Paginas lidas: ' . $result['pages']);
        $this->line('Registros recebidos: ' . $result['records_received']);
        $this->line('Ordens criadas: ' . $result['orders_created']);
        $this->line('Ordens atualizadas: ' . $result['orders_updated']);
        $this->line('Itens criados: ' . $result['items_created']);
        $this->line('Itens atualizados: ' . $result['items_updated']);

        return self::SUCCESS;
    }
}
