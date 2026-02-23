<?php

namespace App\Services\QuoteItem\Actions;

use App\Models\QuoteItem;
use App\Services\QuoteItem\Validators\QuoteItemValidator;
use App\Traits\AuthorizesQuoteItemActions;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class CreateQuoteItemAction
{
    use HandlesActionResponse, AuthorizesQuoteItemActions;

    public function __construct(private int $createdBy) {}

    /**
     * Cria um novo item de orçamento.
     *
     * @param  array  $data
     * @return QuoteItem|null
     */
    public function execute(array $data): ?QuoteItem
    {
        if (! isset($data['quote_id']) || ! self::canModifyQuoteItems($data['quote_id'])) {
            $this->setError('Não é permitido adicionar itens a este orçamento. Apenas orçamentos em rascunho podem ter itens alterados.');
            return null;
        }

        try {
            Log::debug('CreateQuoteItemAction: Criando item de orçamento', [
                'metodo'   => __METHOD__ . '@' . __LINE__,
                'quote_id' => $data['quote_id'],
                'user_id'  => $this->createdBy,
            ]);

            $validated = QuoteItemValidator::validateCreate($data);
            $validated['created_by'] = $this->createdBy;

            $item = QuoteItem::create($validated);

            Log::info('CreateQuoteItemAction: Item de orçamento criado com sucesso', [
                'metodo'   => __METHOD__ . '@' . __LINE__,
                'item_id'  => $item->id,
                'quote_id' => $item->quote_id,
            ]);

            $this->setSuccess();
            return $item;

        } catch (ValidationException $e) {
            $this->setError('Falha de validação dos dados', $e->errors());

            Log::error('CreateQuoteItemAction: ' . $this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'error_code' => $this->getErrorCode(),
                'errors'     => $e->errors(),
                'data'       => $data,
            ]);

            return null;

        } catch (QueryException $e) {
            $this->setError('Erro ao criar item de orçamento no banco de dados');

            Log::error('CreateQuoteItemAction: ' . $this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'error_code' => $this->getErrorCode(),
                'exception'  => $e->getMessage(),
                'data'       => $data,
            ]);

            return null;

        } catch (\Exception $e) {
            $this->setError('Erro inesperado ao criar item de orçamento');

            Log::error('CreateQuoteItemAction: ' . $this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'error_code' => $this->getErrorCode(),
                'exception'  => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
                'data'       => $data,
            ]);

            return null;
        }
    }
}

