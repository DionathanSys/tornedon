<?php

namespace App\Services\FiscalDocumentItem;

use App\Models\FiscalDocumentItem;
use App\Services\FiscalDocumentItem\Actions\CreateFiscalDocumentItemAction;
use App\Services\FiscalDocumentItem\Actions\CreateManyFiscalDocumentItemsAction;
use App\Services\FiscalDocumentItem\Actions\DeleteFiscalDocumentItemAction;
use App\Services\FiscalDocumentItem\Actions\ReorderFiscalDocumentItemsAction;
use App\Services\FiscalDocumentItem\Actions\UpdateFiscalDocumentItemAction;
use App\Traits\HandlesServiceResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FiscalDocumentItemService
{
    use HandlesServiceResponse;

    public function create(array $data, int $createdBy): ?FiscalDocumentItem
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($data, $createdBy) {
                $action = new CreateFiscalDocumentItemAction($createdBy);
                $item = $action->execute($data);

                if ($action->hasError()) {
                    $this->setError(
                        $action->getMessage(),
                        $action->getErrors(),
                        422,
                        $action->getErrorCode()
                    );

                    Log::error($this->getMessage(), [
                        'metodo' => __METHOD__ . '@' . __LINE__,
                        'message' => $this->getMessage(),
                        'error_code' => $this->getErrorCode(),
                        'errors' => $action->getErrors(),
                        'data' => $data,
                        'user_id' => $createdBy,
                    ]);

                    return null;
                }

                if (! $this->reorderItems($item->fiscal_document_id, [
                    'fiscal_document_item_id' => $item->id,
                ])) {
                    return null;
                }

                $item->refresh();

                $this->setSuccess('Item do documento fiscal criado com sucesso');

                return $item;
            });
        } catch (\Exception $e) {
            $this->setError('Erro ao processar criacao do item do documento fiscal');

            Log::error($this->getMessage(), [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'message' => $this->getMessage(),
                'error_code' => $this->getErrorCode(),
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'data' => $data,
                'user_id' => $createdBy,
            ]);

            return null;
        }
    }

    /**
     * Cria multiplos itens em uma unica operacao (bulk insert).
     *
     * @param  array  $items  Array de itens (sem prefixo 'items')
     * @return FiscalDocumentItem[]|null
     */
    public function createMany(array $items, int $createdBy): ?array
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($items, $createdBy) {
                $action = new CreateManyFiscalDocumentItemsAction($createdBy);
                $result = $action->execute($items);

                if ($action->hasError()) {
                    $this->setError(
                        $action->getMessage(),
                        $action->getErrors(),
                        422,
                        $action->getErrorCode()
                    );

                    Log::error($this->getMessage(), [
                        'metodo' => __METHOD__ . '@' . __LINE__,
                        'message' => $this->getMessage(),
                        'error_code' => $this->getErrorCode(),
                        'errors' => $action->getErrors(),
                        'user_id' => $createdBy,
                    ]);

                    return null;
                }

                $fiscalDocumentId = $result[0]->fiscal_document_id ?? $items[0]['fiscal_document_id'] ?? null;

                if ($fiscalDocumentId !== null && ! $this->reorderItems((int) $fiscalDocumentId, [
                    'total_items' => is_countable($result) ? count($result) : null,
                ])) {
                    return null;
                }

                $this->setSuccess('Itens do documento fiscal criados com sucesso');

                return $result;
            });
        } catch (\Exception $e) {
            $this->setError('Erro ao processar criacao dos itens do documento fiscal');

            Log::error($this->getMessage(), [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'message' => $this->getMessage(),
                'error_code' => $this->getErrorCode(),
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => $createdBy,
            ]);

            return null;
        }
    }

    public function update(FiscalDocumentItem $fiscalDocumentItem, array $data, int $updatedBy): ?FiscalDocumentItem
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($fiscalDocumentItem, $data, $updatedBy) {
                $action = new UpdateFiscalDocumentItemAction($updatedBy, $fiscalDocumentItem);
                $updated = $action->execute($data);

                if ($action->hasError()) {
                    $this->setError(
                        $action->getMessage(),
                        $action->getErrors(),
                        422,
                        $action->getErrorCode()
                    );

                    Log::error($this->getMessage(), [
                        'metodo' => __METHOD__ . '@' . __LINE__,
                        'fiscal_document_item_id' => $fiscalDocumentItem->id,
                        'message' => $this->getMessage(),
                        'error_code' => $this->getErrorCode(),
                        'errors' => $action->getErrors(),
                        'data' => $data,
                        'user_id' => $updatedBy,
                    ]);

                    return null;
                }

                $this->setSuccess('Item do documento fiscal atualizado com sucesso');

                Log::info('Item do documento fiscal atualizado com sucesso via service', [
                    'metodo' => __METHOD__ . '@' . __LINE__,
                    'fiscal_document_item_id' => $fiscalDocumentItem->id,
                ]);

                return $updated;
            });
        } catch (\Exception $e) {
            $this->setError('Erro ao atualizar item do documento fiscal');

            Log::error($this->getMessage(), [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'fiscal_document_item_id' => $fiscalDocumentItem->id,
                'error_code' => $this->getErrorCode(),
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'data' => $data,
                'user_id' => $updatedBy,
            ]);

            return null;
        }
    }

    public function delete(FiscalDocumentItem $fiscalDocumentItem): bool
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($fiscalDocumentItem) {
                $fiscalDocumentId = $fiscalDocumentItem->fiscal_document_id;

                $action = new DeleteFiscalDocumentItemAction($fiscalDocumentItem);
                $result = $action->execute();

                if ($action->hasError()) {
                    $this->setError(
                        $action->getMessage(),
                        $action->getErrors(),
                        422,
                        $action->getErrorCode()
                    );

                    Log::error($this->getMessage(), [
                        'metodo' => __METHOD__ . '@' . __LINE__,
                        'fiscal_document_item_id' => $fiscalDocumentItem->id,
                        'message' => $action->getMessage(),
                        'error_code' => $action->getErrorCode(),
                    ]);

                    return false;
                }

                if (! $this->reorderItems($fiscalDocumentId, [
                    'fiscal_document_item_id' => $fiscalDocumentItem->id,
                ])) {
                    return false;
                }

                $this->setSuccess('Item do documento fiscal excluido com sucesso');

                Log::info('Item do documento fiscal excluido com sucesso via service', [
                    'metodo' => __METHOD__ . '@' . __LINE__,
                    'fiscal_document_item_id' => $fiscalDocumentItem->id,
                ]);

                return $result;
            });
        } catch (\Exception $e) {
            $this->setError('Erro ao excluir item do documento fiscal');

            Log::error($this->getMessage(), [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'fiscal_document_item_id' => $fiscalDocumentItem->id,
                'error_code' => $this->getErrorCode(),
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return false;
        }
    }

    private function reorderItems(int $fiscalDocumentId, array $context = []): bool
    {
        $reorderAction = new ReorderFiscalDocumentItemsAction();
        $reordered = $reorderAction->execute($fiscalDocumentId);

        if (! $reordered || $reorderAction->hasError()) {
            $this->setError(
                $reorderAction->getMessage(),
                $reorderAction->getErrors(),
                422,
                $reorderAction->getErrorCode()
            );

            Log::error($this->getMessage(), array_merge([
                'metodo' => __METHOD__ . '@' . __LINE__,
                'message' => $this->getMessage(),
                'error_code' => $this->getErrorCode(),
                'fiscal_document_id' => $fiscalDocumentId,
            ], $context));

            return false;
        }

        return true;
    }
}
