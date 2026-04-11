<?php

namespace App\Jobs;

use App\Notification\NotifyService;
use App\Services\Partner\CompanyPartnerCnpjImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ImportCompanyPartnerCnpjDataJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        private readonly int $companyPartnerId,
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

    public function handle(CompanyPartnerCnpjImportService $service): void
    {
        $imported = $service->import($this->companyPartnerId, $this->userId);

        if (! $imported) {
            NotifyService::error(
                title: 'Erro ao importar dados via CNPJ',
                message: $service->getMessageUser(),
                toDatabase: true,
                users: $this->userId,
            );

            return;
        }

        NotifyService::success(
            title: 'Dados importados via CNPJ',
            message: $service->getMessage(),
            toDatabase: true,
            users: $this->userId,
        );
    }

    public function failed(\Throwable $e): void
    {
        Log::error(__METHOD__ . '@' . __LINE__, [
            'message' => 'Falha definitiva no job de importação por CNPJ',
            'company_partner_id' => $this->companyPartnerId,
            'user_id' => $this->userId,
            'exception' => $e->getMessage(),
        ]);

        NotifyService::error(
            title: 'Erro ao importar dados via CNPJ',
            message: 'O processamento em fila da importação do CNPJ falhou. Tente novamente ou contacte o suporte.',
            toDatabase: true,
            users: $this->userId,
        );
    }
}
