<?php

namespace App\Jobs;

use App\Services\Partner\CompanyPartnerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ReplicateCompanyPartnerJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    /**
     * @param int[] $targetCompanyIds
     */
    public function __construct(
        private readonly int $sourceCompanyPartnerId,
        private readonly array $targetCompanyIds,
        private readonly int $userId,
    ) {
    }

    /**
     * @return int[]
     */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(CompanyPartnerService $companyPartnerService): void
    {
        $companyPartnerService->replicateToCompanies(
            $this->sourceCompanyPartnerId,
            $this->targetCompanyIds,
            $this->userId
        );
    }

    public function failed(\Throwable $e): void
    {
        Log::error(__METHOD__ . '@' . __LINE__, [
            'message' => 'Falha definitiva no job de replicacao de parceiro',
            'source_company_partner_id' => $this->sourceCompanyPartnerId,
            'target_company_ids' => $this->targetCompanyIds,
            'user_id' => $this->userId,
            'exception' => $e->getMessage(),
        ]);

        app(CompanyPartnerService::class)->notifyReplicationFailure(
            $this->userId,
            $this->sourceCompanyPartnerId,
            'O processamento em fila da replicacao do parceiro falhou. Tente novamente ou contacte o suporte.'
        );
    }
}
