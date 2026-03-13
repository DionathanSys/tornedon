<?php

namespace App\Console\Commands;

use App\Models\Equipment;
use App\Models\Partner;
use App\Services\DataReplication\ReplicationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReplicateDataCommand extends Command
{
    /**
     * Assinatura do comando
     *
     * @var string
     */
    protected $signature = 'app:replicate-data
                            {type : O tipo de dado para replicar (partner ou equipment)}
                            {--source-id= : O ID do registro de origem}
                            {--target-companies= : IDs das empresas alvo separados por vírgula}
                            {--confirm : Confirma a execução sem perguntar}';

    /**
     * Descrição do comando
     *
     * @var string
     */
    protected $description = 'Replica Partners e Equipments entre empresas';

    /**
     * Execute o comando
     */
    public function handle(): int
    {
        $type = $this->argument('type');
        $sourceId = $this->option('source-id');
        $targetCompaniesStr = $this->option('target-companies');
        $confirm = $this->option('confirm');

        // Validar tipo
        if (!in_array($type, ['partner', 'equipment'])) {
            $this->error("Tipo inválido: {$type}. Use 'partner' ou 'equipment'.");
            return static::FAILURE;
        }

        // Obter registro de origem
        $source = $this->getSourceRecord($type, $sourceId);
        if (!$source) {
            $this->error("Registro de origem não encontrado.");
            return static::FAILURE;
        }

        $this->info("Tipo: {$type}");
        $this->info("Origem ID: {$source->id}");
        if ($type === 'partner') {
            $this->info("Parceiro: {$source->name}");
        } else {
            $this->info("Equipamento: {$source->name}");
        }

        // Parsear empresas alvo
        $targetCompanies = array_map('intval', array_filter(
            explode(',', $targetCompaniesStr ?? '')
        ));

        if (empty($targetCompanies)) {
            $this->error("Nenhuma empresa alvo especificada.");
            return static::FAILURE;
        }

        $this->info("\nEmpresas alvo: " . implode(', ', $targetCompanies));

        // Confirmação
        if (!$confirm && !$this->confirm("\nDeseja continuar?")) {
            $this->info("Operação cancelada.");
            return static::SUCCESS;
        }

        // Executar replicação
        try {
            $this->info("\nIniciando replicação...");

            $service = app(ReplicationService::class);
            $result = $service->replicate($source, $targetCompanies, $type);

            // Exibir resultados
            $this->displayResults($result);

            if (empty($result['failed'])) {
                $this->info("\n✓ Replicação concluída com sucesso!");
                return static::SUCCESS;
            } else {
                $this->warn("\n⚠ Replicação concluída com erros!");
                return static::FAILURE;
            }
        } catch (\Exception $e) {
            $this->error("Erro durante a replicação: " . $e->getMessage());
            return static::FAILURE;
        }
    }

    /**
     * Obtém o registro de origem
     */
    private function getSourceRecord(string $type, ?string $sourceId)
    {
        if (!$sourceId) {
            $sourceId = $this->ask("Informe o ID do registro de origem");
        }

        $model = $type === 'partner' ? Partner::class : Equipment::class;

        return $model::find($sourceId);
    }

    /**
     * Exibe os resultados da replicação
     */
    private function displayResults(array $result): void
    {
        if (!empty($result['successful'])) {
            $this->info("\n✓ Replicações bem-sucedidas:");
            $table = [];
            foreach ($result['successful'] as $item) {
                $table[] = [
                    'company_id' => $item['company_id'],
                    'status' => '✓ Sucesso',
                ];
            }
            $this->table(['Company ID', 'Status'], $table);
        }

        if (!empty($result['failed'])) {
            $this->error("\n✗ Falhas na replicação:");
            $table = [];
            foreach ($result['failed'] as $item) {
                $table[] = [
                    'company_id' => $item['company_id'],
                    'error' => $item['error'],
                ];
            }
            $this->table(['Company ID', 'Erro'], $table);
        }
    }
}
