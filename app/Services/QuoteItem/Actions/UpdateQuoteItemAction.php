<?php

namespace App\Services\QuoteItem\Actions;

use App\Models\QuoteItem;
use App\Traits\HandlesActionResponse;

class UpdateQuoteItemAction
{
    use HandlesActionResponse;

    public function __construct(private int $updatedBy, private QuoteItem $item) {}

    public function execute(array $data): ?QuoteItem
    {
        try {
            $data['updated_by'] = $this->updatedBy;
            $this->item->update($data);
            $this->setSuccess();
            return $this->item;
        } catch (\Exception $e) {
            $this->setError('Erro ao atualizar item de orçamento', ['exception' => $e->getMessage()]);
            return null;
        }
    }
}
