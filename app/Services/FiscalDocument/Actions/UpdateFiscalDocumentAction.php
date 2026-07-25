<?php

namespace App\Services\FiscalDocument\Actions;

use App\Models\FiscalDocument;
use App\Services\Audit\AuditRecorder;
use App\Services\FiscalDocument\Validators\FiscalDocumentValidatorResolver;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class UpdateFiscalDocumentAction
{
    use HandlesActionResponse;

    public function __construct(
        private int $updatedBy,
        private FiscalDocument $fiscalDocument,
    ) {}

    public function execute(array $data): ?FiscalDocument
    {
        try {
            $audit = app(AuditRecorder::class);
            $before = $audit->snapshot($this->fiscalDocument);

            Log::debug('Iniciando atualização de documento fiscal', [
                'metodo' => __METHOD__.'@'.__LINE__,
                'fiscal_document_id' => $this->fiscalDocument->id,
                'user_id' => $this->updatedBy,
                'data' => $data,
            ]);

            $documentType = $data['document_type'] ?? $this->fiscalDocument->document_type;
            $documentType = $documentType instanceof \BackedEnum ? $documentType->value : $documentType;
            $data['document_type'] = $documentType;

            $validated = FiscalDocumentValidatorResolver::validateUpdate($data, $this->fiscalDocument->id);

            unset($validated['company_id']);
            $payloadData = array_intersect_key($validated, array_flip(['nfe_payload', 'nfse_payload']));
            unset($validated['nfe_payload'], $validated['nfse_payload']);

            $taxDetailData = array_intersect_key($validated, array_flip([
                'freight_data',
                'payment_data',
                'tax_data',
            ]));
            unset($validated['freight_data'], $validated['payment_data'], $validated['tax_data']);

            $validated['updated_by'] = $this->updatedBy;

            $this->fiscalDocument->update($validated);
            if ($taxDetailData !== []) {
                app(UpsertFiscalDocumentTaxDetailAction::class)->execute($this->fiscalDocument, $taxDetailData);
            }
            if ($payloadData !== []) {
                app(UpsertFiscalDocumentPayloadAction::class)->execute($this->fiscalDocument, $payloadData);
            }
            $this->fiscalDocument->refresh();

            $audit->recordModelEvent(
                $this->fiscalDocument,
                'fiscal_document.updated',
                'Documento fiscal atualizado',
                $before,
                $audit->snapshot($this->fiscalDocument),
                $this->updatedBy,
            );

            Log::info('Documento fiscal atualizado com sucesso', [
                'metodo' => __METHOD__.'@'.__LINE__,
                'fiscal_document_id' => $this->fiscalDocument->id,
                'user_id' => $this->updatedBy,
            ]);

            $this->setSuccess();

            return $this->fiscalDocument;
        } catch (ValidationException $e) {
            $this->setError('Falha de validação dos dados', $e->errors());

            Log::error($this->getMessage(), [
                'metodo' => __METHOD__.'@'.__LINE__,
                'message' => $this->getMessage(),
                'error_code' => $this->getErrorCode(),
                'fiscal_document_id' => $this->fiscalDocument->id,
                'errors' => $e->errors(),
                'data' => $data,
                'user_id' => $this->updatedBy,
            ]);

            return null;
        } catch (QueryException $e) {
            $this->setError('Erro ao atualizar documento fiscal no banco de dados');

            Log::error($this->getMessage(), [
                'metodo' => __METHOD__.'@'.__LINE__,
                'message' => $this->getMessage(),
                'error_code' => $this->getErrorCode(),
                'fiscal_document_id' => $this->fiscalDocument->id,
                'error_message' => $e->getMessage(),
                'data' => $data,
                'user_id' => $this->updatedBy,
            ]);

            return null;
        } catch (\Exception $e) {
            $this->setError('Erro inesperado ao atualizar documento fiscal');

            Log::error($this->getMessage(), [
                'metodo' => __METHOD__.'@'.__LINE__,
                'message' => $this->getMessage(),
                'error_code' => $this->getErrorCode(),
                'fiscal_document_id' => $this->fiscalDocument->id,
                'error_message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'data' => $data,
                'user_id' => $this->updatedBy,
            ]);

            return null;
        }
    }
}
