<?php

namespace App\Services\Financial\BankStatement\Normalizers;

final class BradescoOfxNormalizer extends AbstractBankOfxNormalizer
{
    protected function supportedBankIds(): array
    {
        return ['237'];
    }

    public function institutionName(): string
    {
        return 'Bradesco';
    }
}
