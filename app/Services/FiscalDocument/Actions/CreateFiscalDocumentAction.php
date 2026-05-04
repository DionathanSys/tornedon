<?php

namespace App\Services\FiscalDocument\Actions;

use App\Enum\FiscalDocument\DocumentModel;
use App\Enum\FiscalDocument\NfeStatus;
use App\Enum\FiscalDocument\NfseModel;
use App\Enum\FiscalDocument\OperationType;
use App\Models\FiscalDocument;
use App\Services\Audit\AuditRecorder;
use App\Services\FiscalDocument\Validators\FiscalDocumentValidatorResolver;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class CreateFiscalDocumentAction
{
    use HandlesActionResponse;

    public function __construct(
        private int $createdBy,
    ) {}

    public function execute(array $data): ?FiscalDocument
    {
        try {
            $data = $this->normalizeDataBeforeValidation($data);

            Log::debug('Iniciando criação de documento fiscal', [
                'metodo'  => __METHOD__ . '@' . __LINE__,
                'user_id' => $this->createdBy,
                'data'    => $data,
            ]);

            $validated = FiscalDocumentValidatorResolver::validateCreate($data);
            $validated['created_by'] = $this->createdBy;
            $validated = $this->applyInitialFiscalStatus($validated);

            $fiscalDocument = FiscalDocument::create($validated);
            $audit = app(AuditRecorder::class);

            $audit->recordModelEvent(
                $fiscalDocument,
                'fiscal_document.created',
                'Documento fiscal criado',
                null,
                $audit->snapshot($fiscalDocument),
                $this->createdBy,
            );

            Log::info('Documento fiscal criado com sucesso', [
                'metodo'             => __METHOD__ . '@' . __LINE__,
                'fiscal_document_id' => $fiscalDocument->id,
            ]);

            $this->setSuccess();
            return $fiscalDocument;
        } catch (ValidationException $e) {
            $this->setError('Falha de validação dos dados', $e->errors());

            Log::error($this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'message'    => $this->getMessage(),
                'error_code' => $this->getErrorCode(),
                'errors'     => $e->errors(),
                'data'       => $data,
                'user_id'    => $this->createdBy,
            ]);

            return null;
        } catch (QueryException $e) {
            $this->setError('Erro ao salvar documento fiscal no banco de dados');

            Log::error($this->getMessage(), [
                'metodo'        => __METHOD__ . '@' . __LINE__,
                'message'       => $this->getMessage(),
                'error_code'    => $this->getErrorCode(),
                'error_message' => $e->getMessage(),
                'data'          => $data,
                'user_id'       => $this->createdBy,
            ]);

            return null;
        } catch (\Exception $e) {
            $this->setError('Erro inesperado ao criar documento fiscal');

            Log::error($this->getMessage(), [
                'metodo'        => __METHOD__ . '@' . __LINE__,
                'message'       => $this->getMessage(),
                'error_code'    => $this->getErrorCode(),
                'error_message' => $e->getMessage(),
                'trace'         => $e->getTraceAsString(),
                'data'          => $data,
                'user_id'       => $this->createdBy,
            ]);

            return null;
        }
    }

    private function normalizeDataBeforeValidation(array $data): array
    {
        $documentType = $data['document_type'] ?? null;
        $operationType = $data['operation_type'] ?? null;

        if (
            $documentType === DocumentModel::NFSE->value
            && $operationType === OperationType::ENTRADA->value
            && empty($data['nfse_model'])
        ) {
            $data['nfse_model'] = NfseModel::MUNICIPAL->value;
        }

        return $data;
    }

    private function applyInitialFiscalStatus(array $validated): array
    {
        $documentType = $validated['document_type'] ?? null;

        if ($documentType === DocumentModel::NFSE->value) {
            $validated['nfse_status'] = NfeStatus::PENDING->value;
            $validated['nfe_status'] = null;
            $validated['operation_type'] = $validated['operation_type'] ?? OperationType::SAIDA->value;

            return $validated;
        }

        if ($documentType === DocumentModel::NFE->value) {
            $validated['nfe_status'] = NfeStatus::PENDING->value;
            $validated['nfse_status'] = null;
        }

        return $validated;
    }
}
