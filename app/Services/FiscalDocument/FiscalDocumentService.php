<?php

namespace App\Services\FiscalDocument;

use App\Enum\FiscalDocument\Status;
use App\Models\FiscalDocument;
use App\Services\FiscalDocument\Actions\CreateFiscalDocumentAction;
use App\Services\FiscalDocument\Actions\DeleteFiscalDocumentAction;
use App\Services\FiscalDocument\Actions\UpdateFiscalDocumentAction;
use App\Traits\HandlesServiceResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FiscalDocumentService
{
    use HandlesServiceResponse;

    public function create(array $data, int $createdBy): ?FiscalDocument
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($data, $createdBy) {
                $data['status'] = $data['status'] ?? Status::PENDING->value;

                $action = new CreateFiscalDocumentAction($createdBy);
                $fiscalDocument = $action->execute($data);

                if ($action->hasError()) {
                    $this->setError(
                        $action->getMessage(),
                        $action->getErrors(),
                        422,
                        $action->getErrorCode()
                    );

                    Log::error($this->getMessage(), [
                        'metodo'     => __METHOD__ . '@' . __LINE__,
                        'message'    => $this->getMessage(),
                        'error_code' => $this->getErrorCode(),
                        'errors'     => $action->getErrors(),
                        'data'       => $data,
                        'user_id'    => $createdBy,
                    ]);

                    return null;
                }

                $this->setSuccess('Documento fiscal criado com sucesso');

                Log::info('Documento fiscal criado com sucesso via service', [
                    'metodo'             => __METHOD__ . '@' . __LINE__,
                    'fiscal_document_id' => $fiscalDocument->id,
                ]);

                return $fiscalDocument;
            });
        } catch (\Exception $e) {
            $this->setError('Erro ao criar documento fiscal');

            Log::error($this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'error_code' => $this->getErrorCode(),
                'message'    => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
                'data'       => $data,
                'user_id'    => $createdBy,
            ]);

            return null;
        }
    }

    public function update(FiscalDocument $fiscalDocument, array $data, int $updatedBy): ?FiscalDocument
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($fiscalDocument, $data, $updatedBy) {
                $action = new UpdateFiscalDocumentAction($updatedBy, $fiscalDocument);
                $updated = $action->execute($data);

                if ($action->hasError()) {
                    $this->setError(
                        $action->getMessage(),
                        $action->getErrors(),
                        422,
                        $action->getErrorCode()
                    );

                    Log::error($this->getMessage(), [
                        'metodo'             => __METHOD__ . '@' . __LINE__,
                        'fiscal_document_id' => $fiscalDocument->id,
                        'message'            => $this->getMessage(),
                        'error_code'         => $this->getErrorCode(),
                        'errors'             => $action->getErrors(),
                        'data'               => $data,
                        'user_id'            => $updatedBy,
                    ]);

                    return null;
                }

                $this->setSuccess('Documento fiscal atualizado com sucesso');

                Log::info('Documento fiscal atualizado com sucesso via service', [
                    'metodo'             => __METHOD__ . '@' . __LINE__,
                    'fiscal_document_id' => $fiscalDocument->id,
                ]);

                return $updated;
            });
        } catch (\Exception $e) {
            $this->setError('Erro ao atualizar documento fiscal');

            Log::error($this->getMessage(), [
                'metodo'             => __METHOD__ . '@' . __LINE__,
                'fiscal_document_id' => $fiscalDocument->id,
                'error_code'         => $this->getErrorCode(),
                'message'            => $e->getMessage(),
                'trace'              => $e->getTraceAsString(),
                'data'               => $data,
                'user_id'            => $updatedBy,
            ]);

            return null;
        }
    }

    public function delete(FiscalDocument $fiscalDocument): bool
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($fiscalDocument) {
                $action = new DeleteFiscalDocumentAction($fiscalDocument);
                $result = $action->execute();

                if ($action->hasError()) {
                    $this->setError(
                        $action->getMessage(),
                        $action->getErrors(),
                        422,
                        $action->getErrorCode()
                    );

                    Log::error($this->getMessage(), [
                        'metodo'             => __METHOD__ . '@' . __LINE__,
                        'fiscal_document_id' => $fiscalDocument->id,
                        'message'            => $action->getMessage(),
                        'error_code'         => $action->getErrorCode(),
                    ]);

                    return false;
                }

                $this->setSuccess('Documento fiscal excluído com sucesso');

                Log::info('Documento fiscal excluído com sucesso via service', [
                    'metodo'             => __METHOD__ . '@' . __LINE__,
                    'fiscal_document_id' => $fiscalDocument->id,
                ]);

                return $result;
            });
        } catch (\Exception $e) {
            $this->setError('Erro ao excluir documento fiscal');

            Log::error($this->getMessage(), [
                'metodo'             => __METHOD__ . '@' . __LINE__,
                'fiscal_document_id' => $fiscalDocument->id,
                'error_code'         => $this->getErrorCode(),
                'message'            => $e->getMessage(),
                'trace'              => $e->getTraceAsString(),
            ]);

            return false;
        }
    }
}
