<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\SefazDistributionDocument;
use App\Services\Fiscal\Sefaz\SefazDistributionFinancialLinkService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RepairSefazDistributionFinancialLinksCommand extends Command
{
    protected $signature = 'sefaz:repair-financial-links {company : ID da empresa} {--dry-run : Simula sem persistir os vínculos}';

    protected $description = 'Sincroniza os vínculos existentes entre DF-e, notas de entrada e contas a pagar de uma empresa';

    public function handle(SefazDistributionFinancialLinkService $links): int
    {
        if (! Company::query()->whereKey($this->argument('company'))->exists()) {
            $this->error('Empresa não encontrada.');

            return self::FAILURE;
        }
        $errors = 0;
        $processed = 0;
        SefazDistributionDocument::query()->where('company_id', $this->argument('company'))
            ->chunkById(100, function ($documents) use ($links, &$errors, &$processed): void {
                foreach ($documents as $document) {
                    DB::beginTransaction();
                    try {
                        $links->synchronize($document);
                        $this->option('dry-run') ? DB::rollBack() : DB::commit();
                        $processed++;
                    } catch (\Throwable $exception) {
                        DB::rollBack();
                        $errors++;
                        $this->error("DF-e #{$document->id}: {$exception->getMessage()}");
                    }
                }
            });
        $this->info("DF-e processados: {$processed}; conflitos: {$errors}.".($this->option('dry-run') ? ' Simulação: nenhuma alteração persistida.' : ''));

        return $errors ? self::FAILURE : self::SUCCESS;
    }
}
