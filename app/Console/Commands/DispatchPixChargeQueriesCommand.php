<?php

namespace App\Console\Commands;

use App\Jobs\QueryPixChargeJob;
use App\Jobs\RegisterPixChargeJob;
use App\Models\PixCharge;
use Illuminate\Console\Command;

final class DispatchPixChargeQueriesCommand extends Command
{
    protected $signature = 'pix:dispatch-queries {--company_id= : Limita a uma empresa}';

    protected $description = 'Despacha consultas de cobranças PIX registradas';

    public function handle(): int
    {
        $query = PixCharge::query()
            ->where(function ($query): void {
                $query->whereIn('status', ['registered', 'processing', 'uncertain', 'partially_paid'])
                    ->orWhere(fn ($pending) => $pending->where('status', 'pending_registration')->whereNotNull('service_order_id'));
            })
            ->whereHas('connection', fn ($query) => $query->where('status', 'active'))
            ->where(function ($query): void {
                $query
                    ->whereNull('last_synchronized_at')
                    ->orWhere('last_synchronized_at', '<=', now()->subMinutes(5));
            });

        if ($this->option('company_id')) {
            $query->where('company_id', (int) $this->option('company_id'));
        }

        $count = 0;
        $query->select('id', 'status')->chunkById(100, function ($charges) use (&$count): void {
            foreach ($charges as $charge) {
                if ($charge->status->value === 'pending_registration') {
                    RegisterPixChargeJob::dispatch($charge->id);
                } else {
                    QueryPixChargeJob::dispatch($charge->id);
                }
                $count++;
            }
        });

        $this->info("{$count} consulta(s) PIX despachada(s).");

        return self::SUCCESS;
    }
}
