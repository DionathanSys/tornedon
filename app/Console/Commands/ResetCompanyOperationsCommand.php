<?php

namespace App\Console\Commands;

use App\Services\Company\CompanyOperationalResetService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

class ResetCompanyOperationsCommand extends Command
{
    protected $signature = 'company:reset-operations
        {company : ID da empresa}
        {--dry-run : Exibe o diagnóstico sem alterar dados (padrão)}
        {--execute : Executa a limpeza operacional}
        {--json : Emite o diagnóstico completo em JSON, somente em simulação}
        {--workers-stopped : Confirma que workers e agendadores estão pausados e sem tarefas em execução}';

    protected $description = 'Simula ou reinicia operações de uma empresa, preservando cadastros e histórico fiscal';

    public function handle(CompanyOperationalResetService $service): int
    {
        if (! ctype_digit((string) $this->argument('company')) || (int) $this->argument('company') < 1) {
            $this->error('Informe um ID de empresa válido.');

            return self::FAILURE;
        }
        if ($this->option('execute') && ($this->option('dry-run') || $this->option('json'))) {
            $this->error('--execute não pode ser combinado com --dry-run ou --json.');

            return self::FAILURE;
        }

        $companyId = (int) $this->argument('company');
        $lock = Cache::lock('company-operational-reset:'.$companyId, 86400);
        $locked = false;
        try {
            if ($this->option('execute')) {
                if (! app()->isDownForMaintenance() || ! $this->option('workers-stopped')) {
                    $this->error('Para executar: coloque a aplicação em manutenção, pause workers/agendadores, aguarde as tarefas em execução e informe --workers-stopped.');

                    return self::FAILURE;
                }
                $locked = $lock->get();
                if (! $locked) {
                    $this->error('Já existe um reinício em andamento para esta empresa.');

                    return self::FAILURE;
                }
            }

            $plan = $service->plan($companyId);
            if ($this->option('json')) {
                $this->line(json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            } else {
                $this->renderPlan($plan);
            }
            if ($plan['blockers']) {
                if (! $this->option('json')) {
                    $this->error('Existem impedimentos. Nenhum dado foi alterado.');
                }

                return self::FAILURE;
            }
            if (! $this->option('execute')) {
                if (! $this->option('json')) {
                    $this->info('Simulação concluída. Nenhum dado foi alterado e nenhuma API foi chamada.');
                }

                return self::SUCCESS;
            }
            if (! $this->input->isInteractive() || $this->ask("Backup realizado? Para excluir as operações, digite o ID da empresa ({$companyId})") !== (string) $companyId) {
                $this->error('Execução não confirmada.');

                return self::FAILURE;
            }

            $service->execute($companyId);
            $this->info("Reinício operacional da empresa {$companyId} concluído. Estoque e saldos financeiros zerados.");

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            if ($locked) {
                $lock->release();
            }
        }
    }

    private function renderPlan(array $plan): void
    {
        $company = $plan['company'];
        $this->info("Empresa #{$company['id']}: {$company['name']} — {$company['document']}");
        $this->table(['Excluir', 'Quantidade'], collect($plan['delete'])->map(fn ($ids, $table) => [$table, count($ids)])->values()->all());
        $this->table(['Preservar', 'Quantidade'], collect($plan['preserve'])->map(fn ($count, $table) => [$table, $count])->values()->all());
        $this->table(['Desvincular', 'Quantidade'], collect($plan['detach'])->map(fn ($change) => [$change['table'].'.'.$change['column'], count($change['ids'])])->all());

        $this->info('Documentos fiscais — validar os estados locais com o provedor quando houver dúvida');
        $this->table(['ID', 'Tipo', 'Número', 'Status fiscal', 'Destino'], array_map(fn ($doc) => array_values($doc), $plan['fiscal']));
        foreach ($plan['banking'] as $table => $charges) {
            $this->info("{$table} — exclusão LOCAL; cobranças não serão canceladas no banco");
            $this->table(['ID', 'Conexão', 'Identificação no provedor', 'Status local', 'Valor (R$)', 'Vencimento'], array_map(fn ($row) => array_values((array) $row), $charges));
        }
        $this->info('Contas financeiras — saldo de abertura e saldo atual serão zerados');
        $this->table(['ID', 'Conta', 'Abertura (R$)', 'Atual (R$)'], array_map(fn ($row) => array_values((array) $row), $plan['balances']['financial_accounts']));
        $this->info('Estoque — quantidades e custos serão zerados; mínimos, máximos e preços de cadastro preservados');
        $this->table(['ID estoque', 'Produto', 'Total', 'Reservado', 'Custo médio'], array_map(fn ($row) => array_values((array) $row), $plan['balances']['stock']));

        $this->newLine();
        $this->line('Validar antes de executar:');
        foreach ($plan['validation'] as $index => $item) {
            $this->line(($index + 1).'. '.$item);
        }
        $this->line('O diagnóstico usa dados locais, sem consultar ou modificar a IntegraBancos/IntegraNotas.');
        $this->line('Use --dry-run --json para obter todos os IDs de exclusão e desvinculação.');
        foreach ($plan['blockers'] as $blocker) {
            $this->error($blocker);
        }
    }
}
