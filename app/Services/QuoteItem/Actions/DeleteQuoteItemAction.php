<?php

namespace App\Services\QuoteItem\Actions;

use App\Models\QuoteItem;
use App\Traits\AuthorizesQuoteItemActions;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

class DeleteQuoteItemAction
{
    use HandlesActionResponse, AuthorizesQuoteItemActions;

    public function __construct(
        private QuoteItem $item,
    ) {}

    /**
     * Exclui (soft delete) um item de orçamento.
     *
     * @return bool
     */
    public function execute(): bool
    {
        if (! self::canDeleteQuoteItem($this->item->quote_id)) {
            $this->setError('Não é permitido excluir itens deste orçamento. Apenas orçamentos em rascunho podem ter itens removidos.');
            return false;
        }

        try {
            Log::debug('DeleteQuoteItemAction: Excluindo item de orçamento', [
                'metodo'  => __METHOD__ . '@' . __LINE__,
                'item_id' => $this->item->id,
            ]);

            $result = $this->item->delete();

            Log::info('DeleteQuoteItemAction: Item excluído com sucesso', [
                'metodo'  => __METHOD__ . '@' . __LINE__,
                'item_id' => $this->item->id,
            ]);

            $this->setSuccess();
            return (bool) $result;

        } catch (QueryException $e) {
            $this->setError('Erro ao excluir item de orçamento. Ele pode estar vinculado a outros registros.');

            Log::error('DeleteQuoteItemAction: ' . $this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'error_code' => $this->getErrorCode(),
                'exception'  => $e->getMessage(),
                'item_id'    => $this->item->id,
            ]);

            return false;

        } catch (\Exception $e) {
            $this->setError('Erro inesperado ao excluir item de orçamento');

            Log::error('DeleteQuoteItemAction: ' . $this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'error_code' => $this->getErrorCode(),
                'exception'  => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
                'item_id'    => $this->item->id,
            ]);

            return false;
        }
    }

    /**
     * Exclui permanentemente um item de orçamento (force delete).
     *
     * @return bool
     */
    public function forceDelete(): bool
    {
        try {
            Log::debug('DeleteQuoteItemAction: Exclusão permanente de item de orçamento', [
                'metodo'  => __METHOD__ . '@' . __LINE__,
                'item_id' => $this->item->id,
            ]);

            $result = $this->item->forceDelete();

            Log::info('DeleteQuoteItemAction: Item excluído permanentemente com sucesso', [
                'metodo'  => __METHOD__ . '@' . __LINE__,
                'item_id' => $this->item->id,
            ]);

            $this->setSuccess();
            return (bool) $result;

        } catch (QueryException $e) {
            $this->setError('Erro ao excluir permanentemente o item de orçamento.');

            Log::error('DeleteQuoteItemAction (force): ' . $this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'error_code' => $this->getErrorCode(),
                'exception'  => $e->getMessage(),
                'item_id'    => $this->item->id,
            ]);

            return false;

        } catch (\Exception $e) {
            $this->setError('Erro inesperado ao excluir permanentemente o item de orçamento');

            Log::error('DeleteQuoteItemAction (force): ' . $this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'error_code' => $this->getErrorCode(),
                'exception'  => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
                'item_id'    => $this->item->id,
            ]);

            return false;
        }
    }
}

