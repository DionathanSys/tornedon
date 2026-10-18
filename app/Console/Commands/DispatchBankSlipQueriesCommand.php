<?php

namespace App\Console\Commands;

use App\Jobs\QueryBankSlipJob;
use App\Models\BankSlip;
use Illuminate\Console\Command;

final class DispatchBankSlipQueriesCommand extends Command
{
    protected $signature = 'bank-slips:dispatch-queries {--company_id= : Limita a uma empresa}';

    protected $description = 'Recupera emissões, pagamentos e operações pendentes de boletos';

    public function handle(): int
    {
        $count = 0;
        BankSlip::query()->whereIn('status', ['registered', 'processing', 'uncertain', 'partially_paid', 'cancel_pending', 'update_pending'])
            ->whereHas('connection', fn ($query) => $query->where('status', 'active'))
            ->where(fn ($query) => $query->whereNull('last_synchronized_at')->orWhere('last_synchronized_at', '<=', now()->subMinutes(5)))
            ->when($this->option('company_id'), fn ($query) => $query->where('company_id', (int) $this->option('company_id')))
            ->select('id')->chunkById(100, function ($charges) use (&$count): void {
                foreach ($charges as $charge) {
                    QueryBankSlipJob::dispatch($charge->id);
                    $count++;
                }
            });
        $this->info("{$count} consulta(s) de boleto despachada(s).");

        return self::SUCCESS;
    }
}
