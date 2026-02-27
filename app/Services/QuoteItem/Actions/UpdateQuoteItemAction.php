<?php

namespace App\Services\QuoteItem\Actions;

use App\Models\QuoteItem;
use App\Services\QuoteItem\Validators\QuoteItemValidator;
use App\Traits\AuthorizesQuoteItemActions;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class UpdateQuoteItemAction
{
    use HandlesActionResponse, AuthorizesQuoteItemActions;

    public function __construct(
        private int       $updatedBy,
        private QuoteItem $item,
    ) {}

    /**
     * Atualiza um item de orçamento existente.
     *
     * @param  array  $data
     * @return QuoteItem|null
     */
    public function execute(array $data): ?QuoteItem
    {
        if (! self::canModifyQuoteItems($this->item->quote_id)) {
            $this->setError('Não é permitido atualizar itens deste orçamento. Apenas orçamentos em rascunho podem ter itens alterados.');
            return null;
        }

        try {
            Log::debug('UpdateQuoteItemAction: Atualizando item de orçamento', [
                'metodo'  => __METHOD__ . '@' . __LINE__,
                'item_id' => $this->item->id,
                'user_id' => $this->updatedBy,
                'data'    => $data,
            ]);

            $validated = QuoteItemValidator::validateUpdate($data);
            // $validated['updated_by'] = $this->updatedBy;

            Log::debug('UpdateQuoteItemAction: Dados validados para atualização', [
                'metodo'    => __METHOD__ . '@' . __LINE__,
                'item'   => $this->item,
                'validated' => $validated,
            ]);
            
            Log::debug('Dirty attributes', $this->item->getDirty());
            
            $this->item->update($validated);
            $this->item->refresh();

            Log::info('UpdateQuoteItemAction: Item de orçamento atualizado com sucesso', [
                'metodo'  => __METHOD__ . '@' . __LINE__,
                'item_id' => $this->item->id,
            ]);

            $this->setSuccess();
            return $this->item;

        } catch (ValidationException $e) {
            $this->setError('Falha de validação dos dados', $e->errors());

            Log::error('UpdateQuoteItemAction: ' . $this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'error_code' => $this->getErrorCode(),
                'errors'     => $e->errors(),
                'item_id'    => $this->item->id,
            ]);

            return null;

        } catch (QueryException $e) {
            $this->setError('Erro ao atualizar item de orçamento no banco de dados');

            Log::error('UpdateQuoteItemAction: ' . $this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'error_code' => $this->getErrorCode(),
                'exception'  => $e->getMessage(),
                'item_id'    => $this->item->id,
            ]);

            return null;

        } catch (\Exception $e) {
            $this->setError('Erro inesperado ao atualizar item de orçamento');

            Log::error('UpdateQuoteItemAction: ' . $this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'error_code' => $this->getErrorCode(),
                'exception'  => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
                'item_id'    => $this->item->id,
            ]);

            return null;
        }
    }
}

