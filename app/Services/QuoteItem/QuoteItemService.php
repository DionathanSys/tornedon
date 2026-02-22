<?php

namespace App\Services\QuoteItem;

use App\Models\QuoteItem;
use App\Services\QuoteItem\Actions\CreateQuoteItemAction;
use App\Services\QuoteItem\Actions\UpdateQuoteItemAction;
use App\Services\QuoteItem\Actions\DeleteQuoteItemAction;
use App\Traits\HandlesServiceResponse;

class QuoteItemService
{
    use HandlesServiceResponse;

    public function create(array $data, int $createdBy): ?QuoteItem
    {
        $action = new CreateQuoteItemAction($createdBy);
        $item = $action->execute($data);

        if ($action->isSuccess()) {
            $this->setSuccess('Item de orçamento criado com sucesso');
            return $item;
        }

        $this->setError($action->getMessage(), $action->getErrors());
        return null;
    }

    public function update(QuoteItem $item, array $data, int $updatedBy): ?QuoteItem
    {
        $action = new UpdateQuoteItemAction($updatedBy, $item);
        $result = $action->execute($data);

        if ($action->isSuccess()) {
            $this->setSuccess('Item de orçamento atualizado com sucesso');
            return $result;
        }

        $this->setError($action->getMessage(), $action->getErrors());
        return null;
    }

    public function delete(QuoteItem $item, int $deletedBy): bool
    {
        $action = new DeleteQuoteItemAction($deletedBy, $item);
        $result = $action->execute();

        if ($action->isSuccess()) {
            $this->setSuccess('Item de orçamento excluído com sucesso');
            return true;
        }

        $this->setError($action->getMessage(), $action->getErrors());
        return false;
    }
}
