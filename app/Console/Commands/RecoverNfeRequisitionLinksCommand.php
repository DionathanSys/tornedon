<?php

namespace App\Console\Commands;

use App\Models\FiscalDocument;
use App\Services\FiscalDocument\RecoverNfeRequisitionLinksService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

class RecoverNfeRequisitionLinksCommand extends Command
{
    protected $signature = 'fiscal:recover-requisition-links
        {document : ID do documento fiscal}
        {--company= : ID da empresa; se informado, exige que a nota pertença a ela}
        {--reuse-legacy-stock : Valida o reaproveitamento de saídas antigas, sem gerar nova baixa}
        {--apply : Grava os vínculos quando não houver pendências no diagnóstico}';

    protected $description = 'Diagnostica e recupera vínculos de itens de NF-e antiga com a requisição da fatura';

    public function handle(RecoverNfeRequisitionLinksService $service): int
    {
        if (! ctype_digit((string) $this->argument('document')) || (int) $this->argument('document') < 1
            || ($this->option('company') !== null && (! ctype_digit((string) $this->option('company')) || (int) $this->option('company') < 1))) {
            $this->error('Informe IDs positivos para documento e empresa.');

            return self::FAILURE;
        }

        try {
            $document = FiscalDocument::query()
                ->when($this->option('company') !== null, fn ($query) => $query->where('company_id', (int) $this->option('company')))
                ->findOrFail((int) $this->argument('document'));
            $this->info("NF-e #{$document->id} | Empresa #{$document->company_id} | Fatura #{$document->invoice_id}");
            $report = $service->execute($document->id, $document->company_id, (bool) $this->option('apply'), (bool) $this->option('reuse-legacy-stock'));
        } catch (ModelNotFoundException) {
            $this->error('Documento ou fatura não encontrado na empresa informada.');

            return self::FAILURE;
        } catch (ValidationException $exception) {
            $this->error(implode(' ', $exception->validator->errors()->all()));

            return self::FAILURE;
        }

        $this->table(['Item NF', 'Produto', 'Quantidade', 'Unidade', 'Candidatos (itens req.)', 'Origem', 'Diagnóstico'], $report['rows']);
        if ($report['errors'] !== []) {
            foreach ($report['errors'] as $error) {
                $this->error($error);
            }
            $this->warn('Nenhum vínculo alterado. Resolva as pendências e execute o diagnóstico novamente.');

            return self::FAILURE;
        }

        if ($report['applied']) {
            $this->info("{$report['missing']} vínculo(s) recuperado(s). Gere o preview novamente na tela da nota.");
            if ($report['legacy'] > 0) {
                $this->info("{$report['legacy']} saída(s) antiga(s) vinculada(s) para reaproveitamento fiscal. Nenhuma nova baixa gerada.");
            }
        } elseif ($report['missing'] === 0 && $report['legacy'] === 0) {
            $this->info('Todos os itens já possuem vínculos compatíveis.');
        } else {
            $this->info("Diagnóstico concluído: {$report['missing']} vínculo(s) recuperável(is). Nenhuma alteração gravada. Execute com --apply para aplicar.");
            if ($report['legacy'] > 0) {
                $this->info("{$report['legacy']} saída(s) antiga(s) validada(s). Mantenha --reuse-legacy-stock ao executar com --apply.");
            }
        }

        return self::SUCCESS;
    }
}
