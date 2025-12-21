<?php

namespace App\Services\Partner\Actions;

use App\Domain\DTO\Partner\PartnerDTO;
use App\Traits\HandlesActionResponse;

class CreatePartner
{
    use HandlesActionResponse;

    public function execute(array $data): void
    {
        // Em caso do parceiro já existir, não retorna erro, apenas marca como sucesso
    }

    private function validate(array $data): bool
    {
        // Validation logic for partner data
        return true;
    }
}
