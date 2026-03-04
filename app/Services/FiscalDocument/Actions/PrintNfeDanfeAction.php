<?php

namespace App\Services\FiscalDocument\Actions;

use App\Enum\FiscalDocument\NfeStatus;
use App\Models\FiscalDocument;
use App\Services\Fiscal\NfeConfigService;
use App\Traits\HandlesActionResponse;
use Illuminate\Support\Facades\Log;

/**
 * Retorna o PDF/DANFE de uma NF-e autorizada via API IntegraNotas.
 *
 * O PDF é retornado em base64 diretamente da API — não é persistido no banco.
 * Para download, decodificar e entregar como StreamedResponse.
 */
class PrintNfeDanfeAction
{
    use HandlesActionResponse;

    public function execute(FiscalDocument $fiscalDocument): ?string
    {
        try {
            if (! $fiscalDocument->isAutorizado()) {
                $this->setError('O DANFE só pode ser gerado para NF-e autorizada. Status atual: ' . $fiscalDocument->nfe_status?->description());
                return null;
            }

            if (empty($fiscalDocument->document_key)) {
                $this->setError('Chave de acesso não encontrada no documento fiscal.');
                return null;
            }

            $configService = app(NfeConfigService::class);
            $sdk = new \CloudDfe\SdkPHP\Nfe($configService->buildSdkParams($fiscalDocument->company_id));

            $resp = $sdk->pdf(['chave' => $fiscalDocument->document_key]);

            if (! ($resp->sucesso ?? false)) {
                $this->setError($resp->mensagem ?? 'Erro ao gerar DANFE');
                return null;
            }

            $this->setSuccess('DANFE gerado com sucesso.');
            return $resp->pdf ?? null;

        } catch (\Exception $e) {
            $this->setError('Erro ao gerar DANFE: ' . $e->getMessage());

            Log::error('PrintNfeDanfeAction: exceção', [
                'metodo'             => __METHOD__ . '@' . __LINE__,
                'fiscal_document_id' => $fiscalDocument->id,
                'exception'          => $e->getMessage(),
                'trace'              => $e->getTraceAsString(),
            ]);

            return null;
        }
    }
}
