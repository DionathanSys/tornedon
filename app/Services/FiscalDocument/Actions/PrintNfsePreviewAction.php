<?php

namespace App\Services\FiscalDocument\Actions;

use App\Models\FiscalDocument;
use App\Models\NfseSequence;
use App\Services\Fiscal\NfseConfigService;
use App\Services\FiscalDocument\Resolvers\NfseEmissionCityResolver;
use App\Traits\HandlesActionResponse;
use CloudDfe\SdkPHP\Nfse;
use Illuminate\Support\Facades\Log;

/**
 * Gera uma pré-visualização (preview) da NFS-e sem enviá-la à API.
 *
 * Útil para conferência antes da emissão e usa o mesmo builder do envio real.
 * Na API v2 nacional, o preview retorna apenas o PDF em base64.
 */
class PrintNfsePreviewAction
{
    use HandlesActionResponse;

    public function execute(FiscalDocument $fiscalDocument): ?array
    {
        try {
            $previewDocument = clone $fiscalDocument;

            Log::debug('PrintNfsePreviewAction: gerando preview de NFS-e', [
                'fiscal_document_id' => $fiscalDocument->id,
                'rps_number' => $fiscalDocument->rps_number,
                'rps_series' => $fiscalDocument->rps_series,
                'nfse_model' => $fiscalDocument->nfse_model,
            ]);

            // Se o documento ainda não tem RPS reservado, usa peek para
            // mostrar no preview o próximo número real sem consumir a sequência.
            if (empty($previewDocument->rps_number) || (int) $previewDocument->rps_number < 1) {
                $configService = app(NfseConfigService::class);
                $serie = $previewDocument->rps_series
                                  ?? $configService->resolveSerie($previewDocument->company_id);

                $previewNumber = NfseSequence::peekNextNumber(
                    $previewDocument->company_id,
                    $serie,
                );

                // Atribui temporariamente em uma copia do model para nao contaminar o registro persistido.
                $previewDocument->rps_number = (string) $previewNumber;
                $previewDocument->rps_series = $serie;
            }

            // Monta o payload igual ao de envio real
            $buildAction = new BuildNfsePayloadAction;
            $payload = $buildAction->execute($previewDocument);

            if ($payload === null) {
                $this->setError($buildAction->getMessage());

                return null;
            }

            unset($payload['servico']['valor_recebido']);

            $configService = app(NfseConfigService::class);
            $sdkParams = $configService->buildSdkParams(
                $fiscalDocument->company_id,
                NfseConfigService::OPERATION_PREVIEW,
            );

            if (app(NfseEmissionCityResolver::class)->resolve($previewDocument) === NfseConfigService::PINHALZINHO_SC_IBGE_CODE) {
                $sdkParams['version'] = NfseConfigService::API_VERSION_V1;
            }

            $sdk = new Nfse($sdkParams);

            Log::debug('PrintNfsePreviewAction: enviando payload para geração do preview', [
                'fiscal_document_id' => $fiscalDocument->id,
                'payload' => $payload,
            ]);

            $resp = $sdk->preview($payload);

            if (! ($resp->sucesso ?? false)) {
                $msgErro = $resp->mensagem ?? 'Erro ao gerar preview da NFS-e';
                $this->setError($msgErro, (array) ($resp->erros ?? []));
                Log::warning('PrintNfsePreviewAction: falha na geração do preview', [
                    'fiscal_document_id' => $fiscalDocument->id,
                    'codigo' => $resp->codigo ?? null,
                    'mensagem' => $msgErro,
                    'resp' => $resp,
                ]);

                return null;
            }

            if (empty($resp->pdf)) {
                $this->setError('A API não retornou o PDF do preview da NFS-e.');

                Log::warning('PrintNfsePreviewAction: preview sem PDF na resposta', [
                    'fiscal_document_id' => $fiscalDocument->id,
                    'codigo' => $resp->codigo ?? null,
                    'resp' => $resp,
                ]);

                return null;
            }

            Log::info('PrintNfsePreviewAction: preview gerado com sucesso', [
                'fiscal_document_id' => $fiscalDocument->id,
                'rps_number' => $previewDocument->rps_number,
                'pdf_gerado' => ! empty($resp->pdf ?? null),
            ]);

            $this->setSuccess();

            return [
                'pdf' => $resp->pdf ?? null,
            ];

        } catch (\Exception $e) {
            $msgErro = 'Erro ao gerar preview da NFS-e: '.$e->getMessage();
            $this->setError($msgErro);

            Log::error('PrintNfsePreviewAction: exceção capturada', [
                'metodo' => __METHOD__.'@'.__LINE__,
                'fiscal_document_id' => $fiscalDocument->id,
                'company_id' => $fiscalDocument->company_id,
                'exception' => $e->getMessage(),
                'erro_classe' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);

            return null;
        }
    }
}
