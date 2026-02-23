<?php

namespace App\Services\QuoteItem;

use App\Models\QuoteItem;
use App\Services\QuoteItem\Actions\CreateQuoteItemAction;
use App\Services\QuoteItem\Actions\DeleteQuoteItemAction;
use App\Services\QuoteItem\Actions\UpdateQuoteItemAction;
use App\Traits\HandlesServiceResponse;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class QuoteItemService
{
    use HandlesServiceResponse;

    /**
     * Lista todos os itens de um orçamento.
     */
    public function list(int $quoteId): Collection
    {
        return QuoteItem::where('quote_id', $quoteId)
            ->with(['product', 'service'])
            ->orderBy('sequence', 'asc')
            ->orderBy('created_at', 'asc')
            ->get();
    }

    /**
     * Busca um item pelo ID.
     */
    public function find(int $id): ?QuoteItem
    {
        return QuoteItem::with(['product', 'service', 'quote'])
            ->find($id);
    }

    /**
     * Cria um novo item de orçamento.
     */
    public function create(array $data, int $createdBy): ?QuoteItem
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($data, $createdBy) {
                $action = new CreateQuoteItemAction($createdBy);
                $item = $action->execute($data);

                if ($action->hasError()) {
                    $this->setError(
                        $action->getMessage(),
                        $action->getErrors(),
                        422,
                        $action->getErrorCode()
                    );

                    Log::error('QuoteItemService: ' . $this->getMessage(), [
                        'metodo'     => __METHOD__ . '@' . __LINE__,
                        'error_code' => $this->getErrorCode(),
                        'errors'     => $action->getErrors(),
                        'data'       => $data,
                    ]);

                    return null;
                }

                $this->setSuccess('Item criado com sucesso');
                return $item;
            });

        } catch (\Exception $e) {
            $this->setError('Erro ao processar criação do item');

            Log::error('QuoteItemService: ' . $this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'error_code' => $this->getErrorCode(),
                'exception'  => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
                'data'       => $data,
            ]);

            return null;
        }
    }

    /**
     * Atualiza um item de orçamento existente.
     */
    public function update(QuoteItem $item, array $data, int $updatedBy): ?QuoteItem
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($item, $data, $updatedBy) {
                $action = new UpdateQuoteItemAction($updatedBy, $item);
                $result = $action->execute($data);

                if ($action->hasError()) {
                    $this->setError(
                        $action->getMessage(),
                        $action->getErrors(),
                        422,
                        $action->getErrorCode()
                    );

                    Log::error('QuoteItemService: ' . $this->getMessage(), [
                        'metodo'     => __METHOD__ . '@' . __LINE__,
                        'error_code' => $this->getErrorCode(),
                        'errors'     => $action->getErrors(),
                        'item_id'    => $item->id,
                    ]);

                    return null;
                }

                $this->setSuccess('Item atualizado com sucesso');
                return $result;
            });

        } catch (\Exception $e) {
            $this->setError('Erro ao processar atualização do item');

            Log::error('QuoteItemService: ' . $this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'error_code' => $this->getErrorCode(),
                'exception'  => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
                'item_id'    => $item->id,
            ]);

            return null;
        }
    }

    /**
     * Exclui (soft delete) um item de orçamento.
     */
    public function delete(QuoteItem $item): bool
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($item) {
                $action = new DeleteQuoteItemAction($item);
                $result = $action->execute();

                if ($action->hasError()) {
                    $this->setError(
                        $action->getMessage(),
                        $action->getErrors(),
                        422,
                        $action->getErrorCode()
                    );

                    Log::error('QuoteItemService: ' . $this->getMessage(), [
                        'metodo'     => __METHOD__ . '@' . __LINE__,
                        'error_code' => $this->getErrorCode(),
                        'errors'     => $action->getErrors(),
                        'item_id'    => $item->id,
                    ]);

                    return false;
                }

                $this->setSuccess('Item excluído com sucesso');
                return $result;
            });

        } catch (\Exception $e) {
            $this->setError('Erro ao processar exclusão do item');

            Log::error('QuoteItemService: ' . $this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'error_code' => $this->getErrorCode(),
                'exception'  => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
                'item_id'    => $item->id,
            ]);

            return false;
        }
    }

    /**
     * Exclui permanentemente um item de orçamento.
     */
    public function forceDelete(QuoteItem $item): bool
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($item) {
                $action = new DeleteQuoteItemAction($item);
                $result = $action->forceDelete();

                if ($action->hasError()) {
                    $this->setError(
                        $action->getMessage(),
                        $action->getErrors(),
                        422,
                        $action->getErrorCode()
                    );

                    Log::error('QuoteItemService: ' . $this->getMessage(), [
                        'metodo'     => __METHOD__ . '@' . __LINE__,
                        'error_code' => $this->getErrorCode(),
                        'errors'     => $action->getErrors(),
                        'item_id'    => $item->id,
                    ]);

                    return false;
                }

                $this->setSuccess('Item excluído permanentemente com sucesso');
                return $result;
            });

        } catch (\Exception $e) {
            $this->setError('Erro ao processar exclusão permanente do item');

            Log::error('QuoteItemService: ' . $this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'error_code' => $this->getErrorCode(),
                'exception'  => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
                'item_id'    => $item->id,
            ]);

            return false;
        }
    }
}

