<?php

namespace App\Services\Cnpj\Contracts;

use App\Services\Cnpj\DTO\CnpjProviderResult;

interface CnpjApiProviderInterface
{
    public function name(): string;

    public function consult(string $cnpj): CnpjProviderResult;
}

