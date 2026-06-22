<?php

namespace App\Console\Commands;

use App\Services\TemporaryMigration\TemporaryEquipmentImportService;
use Illuminate\Console\Command;

class ImportTemporaryMigrationEquipmentsCommand extends Command
{
    protected $signature = 'migration:equipments:import
        {company_id : Empresa que recebera os equipamentos}
        {user_id : Usuario responsavel pela importacao}
        {--limit=500 : Quantidade por pagina na API remota}
        {--after-id=0 : Inicia a leitura apos este legacy_id}
        {--updated-from= : Filtra equipamentos alterados a partir desta data}
        {--include-deleted : Inclui equipamentos excluidos logicamente no legado}
        {--parceiro-id= : Filtra equipamentos de um parceiro legado especifico}
        {--max-pages=0 : Limita paginas para execucao controlada}';

    protected $description = 'Importa temporariamente equipamentos da API de migracao do sistema legado';

    public function handle(TemporaryEquipmentImportService $service): int
    {
        $result = $service->import(
            (int) $this->argument('company_id'),
            (int) $this->argument('user_id'),
            [
                'limit' => (int) $this->option('limit'),
                'after_id' => (int) $this->option('after-id'),
                'updated_from' => $this->option('updated-from') ?: null,
                'include_deleted' => (bool) $this->option('include-deleted'),
                'parceiro_id' => $this->option('parceiro-id') !== null ? (int) $this->option('parceiro-id') : null,
                'max_pages' => (int) $this->option('max-pages'),
            ],
        );

        if ($result === null) {
            $this->error($service->getMessageUser() ?: 'Falha ao importar equipamentos.');

            return self::FAILURE;
        }

        $this->info($service->getMessage());
        $this->line('Paginas lidas: ' . $result['pages']);
        $this->line('Registros recebidos: ' . $result['records_received']);
        $this->line('Equipamentos criados: ' . $result['equipments_created']);
        $this->line('Equipamentos atualizados: ' . $result['equipments_updated']);
        $this->line('Soft deletes aplicados: ' . $result['soft_deleted']);
        $this->line('Restauros aplicados: ' . $result['restored']);
        $this->line('Ultimo after_id processado: ' . $result['last_after_id']);

        if ($result['errors'] !== []) {
            $this->warn('Falhas encontradas: ' . count($result['errors']));

            foreach ($result['errors'] as $error) {
                $this->line(sprintf(
                    '- legacy_id=%s | %s',
                    $error['legacy_id'] ?? 'n/d',
                    $error['message'] ?? 'Erro desconhecido',
                ));
            }
        }

        return self::SUCCESS;
    }
}
