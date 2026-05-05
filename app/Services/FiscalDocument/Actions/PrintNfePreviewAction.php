<?php

namespace App\Services\FiscalDocument\Actions;

use App\Models\FiscalDocument;
use App\Models\NfeSequence;
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
            Log::debug('PrintNfePreviewAction: gerando preview de NF-e', [
                'fiscal_document_id' => $fiscalDocument->id,
                'document_number'    => $fiscalDocument->document_number,
                'document_series'    => $fiscalDocument->document_series,
                'operation_nature'   => $fiscalDocument->operation_nature,
            ]);

            // Se o documento ainda não tem número reservado, usa peek para
            // mostrar no preview o próximo número real sem consumir a sequência.
            $rawNature = $fiscalDocument->operation_nature;
            $natureValue = $rawNature instanceof \App\Enum\FiscalDocument\OperationNature
                ? $rawNature->value
                : $rawNature;

            if (empty($natureValue)) {
                $msgErro = 'Natureza da operação não definida. Preencha o campo antes de gerar o preview.';
                $this->setError($msgErro);
                Log::warning('PrintNfePreviewAction: natureza de operação ausente', [
                    'fiscal_document_id' => $fiscalDocument->id,
                ]);
                return null;
            }

            if (empty($fiscalDocument->document_number) || (int) $fiscalDocument->document_number < 1) {
                $configService = app(NfeConfigService::class);
                $serie         = $fiscalDocument->document_series
                                 ?? $configService->resolveSerie($fiscalDocument->company_id);
                $previewNumber = NfeSequence::peekNextNumber(
                    $fiscalDocument->company_id,
                    $serie
                );

                // Atribui temporariamente (sem persistir) para o BuildNfePayloadAction
                $fiscalDocument->document_number = (string) $previewNumber;
                $fiscalDocument->document_series = $serie;
            }

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
                $msgErro = $resp->mensagem ?? 'Erro ao gerar preview da NF-e';
                $this->setError($msgErro, (array) ($resp->erros ?? []));
                Log::warning('PrintNfePreviewAction: falha na geração do preview', [
                    'fiscal_document_id' => $fiscalDocument->id,
                    'codigo'             => $resp->codigo ?? null,
                    'mensagem'           => $msgErro,
                    'resp'               => $resp,
                ]);
                return null;
            }

            Log::info('PrintNfePreviewAction: preview gerado com sucesso', [
                'fiscal_document_id' => $fiscalDocument->id,
                'document_number'    => $fiscalDocument->document_number,
                'pdf_gerado'         => ! empty($resp->pdf ?? null),
                'xml_gerado'         => ! empty($resp->xml ?? null),
            ]);

            $this->setSuccess();

            Log::debug('PrintNfePreviewAction: preview gerado com sucesso', [
                'fiscal_document_id' => $fiscalDocument->id,
                'document_number'    => $fiscalDocument->document_number,
                'resp'               => $resp,
                'pdf_gerado'         => ! empty($resp->pdf ?? null),
                'xml_gerado'         => ! empty($resp->xml ?? null),
            ]);

            return [
                'pdf' => $resp->pdf ?? null,
                'xml' => $resp->xml ?? null,
            ];

        } catch (\Exception $e) {
            $msgErro = 'Erro ao gerar preview da NF-e: ' . $e->getMessage();
            $this->setError($msgErro);

            Log::error('PrintNfePreviewAction: exceção capturada', [
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
