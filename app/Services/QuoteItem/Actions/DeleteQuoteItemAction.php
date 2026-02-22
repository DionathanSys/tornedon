<?php

namespace App\Services\QuoteItem\Actions;

use App\Models\QuoteItem;
use App\Traits\HandlesActionResponse;

class DeleteQuoteItemAction
{
    use HandlesActionResponse;

    public function __construct(private int $deletedBy, private QuoteItem $item) {}

    public function execute(): bool
    {
        try {
            $this->item->deleted_by = $this->deletedBy;
            $this->item->save();
            $this->item->delete();
            $this->setSuccess();
            return true;
        } catch (\Exception $e) {
            $this->setError('Erro ao excluir item de orçamento', ['exception' => $e->getMessage()]);
            return false;
        }
    }
}
