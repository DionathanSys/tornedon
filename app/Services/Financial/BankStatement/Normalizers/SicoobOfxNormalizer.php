<?php

namespace App\Services\Financial\BankStatement\Normalizers;

final class SicoobOfxNormalizer extends AbstractBankOfxNormalizer
{
    protected function supportedBankIds(): array
    {
        return ['756'];
    }

    public function institutionName(): string
    {
        return 'Sicoob';
    }
}
