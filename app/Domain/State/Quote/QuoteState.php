<?php

namespace App\Domain\State\Quote;

use App\Models\Quote;

abstract class QuoteState
{
    protected Quote $quote;

    public function __construct(Quote $quote)
    {
        $this->quote = $quote;
    }

    abstract public function getName(): string;

    // Métodos de transição de estado
    public function canSendForApproval(): bool { return false; }
    public function canApprove(): bool { return false; }
    public function canReject(): bool { return false; }
    public function canEdit(): bool { return false; }
    public function canConvertToProductionOrder(): bool { return false; }

    // Métodos de ação
    public function sendForApproval(int $userId): bool { return false; }
    public function approve(int $userId): bool { return false; }
    public function reject(int $userId, string $reason): bool { return false; }
    public function edit(array $data, int $userId): bool { return false; }
    public function convertToProductionOrder(array $data, int $userId): bool { return false; }
}
