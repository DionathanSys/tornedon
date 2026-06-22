<?php

namespace App\Console\Commands;

use App\Services\TemporaryMigration\TemporaryContactImportService;
use Illuminate\Console\Command;

class ImportTemporaryMigrationContactsCommand extends Command
{
    protected $signature = 'migration:contacts:import
        {company_id}
        {user_id}
        {--limit=500}
        {--after-id=0}
        {--updated-from=}
        {--parceiro-id=}
        {--max-pages=0}';

    protected $description = 'Importa temporariamente contatos da API de migracao do sistema legado';

    public function handle(TemporaryContactImportService $service): int
    {
        $result = $service->import((int) $this->argument('company_id'), (int) $this->argument('user_id'), [
            'limit' => (int) $this->option('limit'),
            'after_id' => (int) $this->option('after-id'),
            'updated_from' => $this->option('updated-from') ?: null,
            'parceiro_id' => $this->option('parceiro-id') !== null ? (int) $this->option('parceiro-id') : null,
            'max_pages' => (int) $this->option('max-pages'),
        ]);

        if ($result === null) {
            $this->error($service->getMessageUser() ?: 'Falha ao importar contatos.');
            return self::FAILURE;
        }

        $this->info($service->getMessage());
        $this->line('Paginas lidas: ' . $result['pages']);
        $this->line('Registros recebidos: ' . $result['records_received']);
        $this->line('Contatos criados: ' . $result['contacts_created']);
        $this->line('Contatos atualizados: ' . $result['contacts_updated']);

        return self::SUCCESS;
    }
}
