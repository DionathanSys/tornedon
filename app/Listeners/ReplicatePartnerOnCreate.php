<?php

namespace App\Listeners;

use App\Models\Partner;
use App\Services\DataReplication\ReplicationService;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Log;

class ReplicatePartnerOnCreate
{
    /**
     * Handle the event.
     */
    public function handle(): void
    {
        // Este listener será disparado quando um Partner é criado
    }

    /**
     * Registra o listener no Eloquent model event
     */
    public static function register(Dispatcher $events): void
    {
        $events->listen('eloquent.created: ' . Partner::class, function ($event) {
            // Obter dados de replicação do request
            $replicateToCompanies = request()->input('replicate_to_companies', []);

            if (empty($replicateToCompanies)) {
                return;
            }

            try {
                app(ReplicationService::class)->replicate($event, $replicateToCompanies, 'partner');
            } catch (\Exception $e) {
                Log::warning('Failed to replicate Partner after creation', [
                    'partner_id' => $event->id,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }
}
