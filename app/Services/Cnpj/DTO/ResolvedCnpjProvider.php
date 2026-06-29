<?php

namespace App\Services\Cnpj\DTO;

use App\Services\Cnpj\Contracts\CnpjApiProviderInterface;

class ResolvedCnpjProvider
{
    public function __construct(
        public readonly string $name,
        public readonly CnpjApiProviderInterface $provider,
        public readonly array $config,
    ) {}
}
