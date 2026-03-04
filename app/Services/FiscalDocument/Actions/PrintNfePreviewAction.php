<?php

namespace App\Services\FiscalDocument\Actions;

use App\Models\FiscalDocument;
use App\Services\Fiscal\NfeConfigService;
use App\Traits\HandlesActionResponse;
use Illuminate\Support\Facades\Log;

/**
 * Gera uma pré-visualização (preview) da NF-e sem enviá-la à SEFAZ.
 *
 * Útil para conferência antes da emissão.
 * Retorna array com 'pdf' (base64) e 'xml' (base64).
 */
class PrintNfePreviewAction
{
    use HandlesActionResponse;

    public function execute(FiscalDocument $fiscalDocument): ?array
    {
        try {
            // Monta o payload igual ao de envio real
            $buildAction = new BuildNfePayloadAction();
            $payload     = $buildAction->execute($fiscalDocument);

            if ($payload === null) {
                $this->setError($buildAction->getMessage());
                return null;
            }

            $configService = app(NfeConfigService::class);
            $sdk = new \CloudDfe\SdkPHP\Nfe($configService->buildSdkParams($fiscalDocument->company_id));

            $resp = $sdk->preview($payload);

            if (! ($resp->sucesso ?? false)) {
                $this->setError($resp->mensagem ?? 'Erro ao gerar preview da NF-e');
                return null;
            }

            $this->setSuccess('Preview gerado com sucesso.');

            return [
                'pdf' => $resp->pdf ?? null,
                'xml' => $resp->xml ?? null,
            ];

        } catch (\Exception $e) {
            $this->setError('Erro ao gerar preview da NF-e: ' . $e->getMessage());

            Log::error('PrintNfePreviewAction: exceção', [
                'metodo'             => __METHOD__ . '@' . __LINE__,
                'fiscal_document_id' => $fiscalDocument->id,
                'exception'          => $e->getMessage(),
                'trace'              => $e->getTraceAsString(),
            ]);

            return null;
        }
    }
}
