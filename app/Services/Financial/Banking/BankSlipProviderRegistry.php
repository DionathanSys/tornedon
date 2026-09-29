<?php

namespace App\Services\Financial\Banking;

use App\Models\BankAccountConnection;
use App\Services\Financial\Banking\Contracts\BankSlipProviderInterface;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class BankSlipProviderRegistry
{
    public function resolve(BankAccountConnection $connection): BankSlipProviderInterface
    {
        $connection->loadMissing('billingProvider');

        $providerKey = (string) ($connection->billingProvider?->key ?? '');
        $providerConfig = (array) config("banking.providers.{$providerKey}", []);
        $providerClass = $connection->billingProvider?->adapter_class
            ?: ($providerConfig['class'] ?? null);

        if (! $connection->billingProvider?->is_active) {
            throw new RuntimeException('O provider bancario da conexao esta inativo.');
        }

        if (! is_string($providerClass) || ! class_exists($providerClass)) {
            Log::error('Provider de boleto nao encontrado.', [
                'connection_id' => $connection->id,
                'provider' => $providerKey,
                'class' => $providerClass,
            ]);

            throw new RuntimeException('Provider bancario nao configurado.');
        }

        $provider = app()->makeWith($providerClass, [
            'connection' => $connection,
            'config' => $providerConfig,
        ]);

        if (! $provider instanceof BankSlipProviderInterface) {
            throw new RuntimeException("A classe {$providerClass} nao implementa BankSlipProviderInterface.");
        }

        return $provider;
    }
}
