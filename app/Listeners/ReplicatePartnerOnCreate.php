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
            // Guard: verificar se realmente é Partner
            if (!($event instanceof Partner)) {
                Log::warning('ReplicatePartnerOnCreate received non-Partner model', [
                    'received_class' => get_class($event),
                ]);
                return;
            }

            // Obter dados de replicação do request
            $replicateToCompanies = request()->input('replicate_to_companies', []);
            $sourceCompanyId = request()->input('source_company_id'); // Empresa onde o partner foi criado

            if (empty($replicateToCompanies)) {
                return;
            }

            try {
                $result = app(ReplicationService::class)->replicateFromSource(
                    $event,
                    $replicateToCompanies,
                    'partner',
                    $sourceCompanyId
                );
                
                Log::info('Partner replicated successfully', [
                    'partner_id' => $event->id,
                    'source_company_id' => $sourceCompanyId,
                    'successful' => count($result['successful']),
                    'failed' => count($result['failed']),
                    'result' => $result,
                ]);
            } catch (\Exception $e) {
                Log::error('Failed to replicate Partner after creation', [
                    'partner_id' => $event->id ?? null,
                    'source_company_id' => $sourceCompanyId,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'replicate_to_companies' => $replicateToCompanies,
                ]);
            }
        });
    }
}
