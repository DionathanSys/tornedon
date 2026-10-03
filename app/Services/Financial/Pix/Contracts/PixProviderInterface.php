<?php

namespace App\Services\Financial\Pix\Contracts;

use App\Models\BankAccountConnection;
use App\Models\PixCharge;
use App\Services\Financial\Pix\DTO\PixProviderResult;

interface PixProviderInterface
{
    public function register(PixCharge $charge): PixProviderResult;

    public function query(PixCharge $charge): PixProviderResult;

    public function supports(BankAccountConnection $connection): bool;
}
