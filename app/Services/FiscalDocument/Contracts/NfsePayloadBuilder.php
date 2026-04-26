<?php

namespace App\Services\FiscalDocument\Contracts;

use App\Models\FiscalDocument;

interface NfsePayloadBuilder
{
    public function supports(FiscalDocument $document): bool;

    public function identifier(): string;

    public function build(FiscalDocument $document): ?array;
}
