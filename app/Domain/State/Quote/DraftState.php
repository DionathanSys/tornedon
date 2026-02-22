<?php

namespace App\Domain\State\Quote;

class DraftState extends QuoteState
{
    public function getName(): string
    {
        return 'draft';
    }

    public function canSendForApproval(): bool { return true; }
    public function canEdit(): bool { return true; }

    public function sendForApproval(int $userId): bool
    {
        // Lógica para transição draft -> pending_approval
        $this->quote->state = 'pending_approval';
        $this->quote->save();
        return true;
    }

    public function edit(array $data, int $userId): bool
    {
        $this->quote->fill($data);
        $this->quote->save();
        return true;
    }
}
