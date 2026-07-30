<?php

namespace App\Services\Financial\BankStatement\Normalizers;

final class InterOfxNormalizer extends AbstractBankOfxNormalizer
{
    protected function supportedBankIds(): array
    {
        return ['077'];
    }

    public function institutionName(): string
    {
        return 'Banco Inter';
    }
}
