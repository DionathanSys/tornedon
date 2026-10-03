<?php

namespace App\Services\Financial\Pix\Providers;

interface IntegraBancosPixClientInterface
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function generate(array $payload): array;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function query(array $payload): array;
}
