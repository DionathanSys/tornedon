<?php

namespace App\Services\Financial\BankStatement\Normalizers;

final class SicrediOfxNormalizer extends AbstractBankOfxNormalizer
{
    protected function supportedBankIds(): array
    {
        return ['748'];
    }

    public function institutionName(): string
    {
        return 'Sicredi';
    }
}
