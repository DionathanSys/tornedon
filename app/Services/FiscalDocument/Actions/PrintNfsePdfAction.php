<?php

namespace App\Services\FiscalDocument\Actions;

use App\Models\FiscalDocument;
use App\Traits\HandlesActionResponse;
use Illuminate\Support\Facades\Log;

/**
 * Gera o PDF da NFS-e autorizada via API IntegraNotas.
 * Retorna o conteúdo em base64.
 */
class PrintNfsePdfAction
{
    use HandlesActionResponse;

    public function execute(FiscalDocument $fiscalDocument): ?string
    {
        try {
            if (empty($fiscalDocument->document_key)) {
                $this->setError('Chave de acesso não encontrada no documento fiscal.');
                return null;
            }

            $configService = app(\App\Services\Fiscal\NfseConfigService::class);
            $sdk = new \CloudDfe\SdkPHP\Nfse($configService->buildSdkParams($fiscalDocument->company_id));

            $resp = $sdk->pdf(['chave' => $fiscalDocument->document_key]);

            if (! ($resp->sucesso ?? false)) {
                $this->setError($resp->mensagem ?? 'Erro ao gerar PDF da NFS-e');
                return null;
            }

            $this->setSuccess();
            return $resp->pdf ?? null;

        } catch (\Exception $e) {
            $this->setError('Erro ao gerar PDF da NFS-e: ' . $e->getMessage());

            Log::error('PrintNfsePdfAction: exceção', [
                'metodo'             => __METHOD__ . '@' . __LINE__,
                'fiscal_document_id' => $fiscalDocument->id,
                'exception'          => $e->getMessage(),
            ]);

            return null;
        }
    }
}
