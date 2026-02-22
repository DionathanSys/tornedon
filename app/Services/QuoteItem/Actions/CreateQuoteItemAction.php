<?php

namespace App\Services\QuoteItem\Actions;

use App\Models\QuoteItem;
use App\Traits\HandlesActionResponse;

class CreateQuoteItemAction
{
    use HandlesActionResponse;

    public function __construct(private int $createdBy) {}

    public function execute(array $data): ?QuoteItem
    {
        try {
            $data['created_by'] = $this->createdBy;
            $item = QuoteItem::create($data);
            $this->setSuccess();
            return $item;
        } catch (\Exception $e) {
            $this->setError('Erro ao criar item de orçamento', ['exception' => $e->getMessage()]);
            return null;
        }
    }
}
