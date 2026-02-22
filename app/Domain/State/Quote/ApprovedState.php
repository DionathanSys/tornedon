<?php

namespace App\Domain\State\Quote;

class ApprovedState extends QuoteState
{
    public function getName(): string
    {
        return 'approved';
    }

    public function canConvertToProductionOrder(): bool { return true; }

    public function convertToProductionOrder(array $data, int $userId): bool
    {
        // Lógica para conversão
        $this->quote->state = 'converted';
        $this->quote->converted_by = $userId;
        $this->quote->converted_at = now();
        $this->quote->save();
        // Aqui pode acionar service para criar ordem de produção
        return true;
    }
}
