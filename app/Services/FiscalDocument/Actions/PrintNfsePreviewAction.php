<?php

namespace App\Services\FiscalDocument\Actions;

use App\Models\FiscalDocument;
use App\Models\NfseSequence;
use App\Services\Fiscal\NfseConfigService;
use App\Traits\HandlesActionResponse;
use Illuminate\Support\Facades\Log;

/**
 * Gera uma pré-visualização (preview) da NFS-e sem enviá-la à API.
 *
 * Útil para conferência antes da emissão.
 * Retorna array com 'pdf' (base64) e 'xml' (base64).
 */
class PrintNfsePreviewAction
{
    use HandlesActionResponse;

    public function execute(FiscalDocument $fiscalDocument): ?array
    {
        try {
            Log::debug('PrintNfsePreviewAction: gerando preview de NFS-e', [
                'fiscal_document_id' => $fiscalDocument->id,
                'rps_number'         => $fiscalDocument->rps_number,
                'rps_series'         => $fiscalDocument->rps_series,
                'nfse_model'         => $fiscalDocument->nfse_model,
            ]);

            // Se o documento ainda não tem RPS reservado, usa peek para
            // mostrar no preview o próximo número real sem consumir a sequência.
            if (empty($fiscalDocument->rps_number) || (int) $fiscalDocument->rps_number < 1) {
                $configService = app(NfseConfigService::class);
                $serie          = $fiscalDocument->rps_series
                                  ?? $configService->resolveSerie($fiscalDocument->company_id);

                $previewNumber = NfseSequence::peekNextNumber(
                    $fiscalDocument->company_id,
                    $serie,
                );

                // Atribui temporariamente (sem persistir) para o BuildNfsePayloadAction
                $fiscalDocument->rps_number  = (string) $previewNumber;
                $fiscalDocument->rps_series  = $serie;
            }

            // Monta o payload igual ao de envio real
            $buildAction = new BuildNfsePayloadAction();
            $payload     = $buildAction->execute($fiscalDocument);

            if ($payload === null) {
                $this->setError($buildAction->getMessage());
                return null;
            }

            unset($payload['servico']['valor_recebido']);

            $configService = app(NfseConfigService::class);
            $sdk = new \CloudDfe\SdkPHP\Nfse($configService->buildSdkParams(
                $fiscalDocument->company_id,
                NfseConfigService::OPERATION_PREVIEW,
            ));

            Log::debug('PrintNfsePreviewAction: enviando payload para geração do preview', [
                'fiscal_document_id' => $fiscalDocument->id,
                'payload'            => $payload,
            ]);
            
            $resp = $sdk->preview($payload);

            if (! ($resp->sucesso ?? false)) {
                $msgErro = $resp->mensagem ?? 'Erro ao gerar preview da NFS-e';
                $this->setError($msgErro, (array) ($resp->erros ?? []));
                Log::warning('PrintNfsePreviewAction: falha na geração do preview', [
                    'fiscal_document_id' => $fiscalDocument->id,
                    'codigo'             => $resp->codigo ?? null,
                    'mensagem'           => $msgErro,
                    'resp'               => $resp,
                ]);
                return null;
            }

            Log::info('PrintNfsePreviewAction: preview gerado com sucesso', [
                'fiscal_document_id' => $fiscalDocument->id,
                'rps_number'         => $fiscalDocument->rps_number,
                'pdf_gerado'         => ! empty($resp->pdf ?? null),
                'xml_gerado'         => ! empty($resp->xml ?? null),
            ]);

            $this->setSuccess('Preview gerado com sucesso.');

            return [
                'pdf' => $resp->pdf ?? null,
                'xml' => $resp->xml ?? null,
            ];

        } catch (\Exception $e) {
            $msgErro = 'Erro ao gerar preview da NFS-e: ' . $e->getMessage();
            $this->setError($msgErro);

            Log::error('PrintNfsePreviewAction: exceção capturada', [
                'metodo'             => __METHOD__ . '@' . __LINE__,
                'fiscal_document_id' => $fiscalDocument->id,
                'company_id'         => $fiscalDocument->company_id,
                'exception'          => $e->getMessage(),
                'erro_classe'        => get_class($e),
                'trace'              => $e->getTraceAsString(),
            ]);

            return null;
        }
    }
}
