<?php

namespace App\Services\Financial\Pix;

use App\Models\BankAccountConnection;
use App\Services\Financial\Pix\Contracts\PixProviderInterface;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class PixProviderRegistry
{
    public function resolve(BankAccountConnection $connection): PixProviderInterface
    {
        $connection->loadMissing('billingProvider');

        $providerKey = (string) ($connection->billingProvider?->key ?? '');
        $providerConfig = (array) config("pix.providers.{$providerKey}", []);
        $providerClass = $providerConfig['class'] ?? null;

        if (! $connection->billingProvider?->is_active) {
            throw new RuntimeException('O provider PIX da conexão está inativo.');
        }

        if (! is_string($providerClass) || ! class_exists($providerClass)) {
            Log::error('Provider PIX não encontrado.', [
                'connection_id' => $connection->id,
                'provider' => $providerKey,
                'class' => $providerClass,
            ]);

            throw new RuntimeException('Provider PIX não configurado.');
        }

        $provider = app()->makeWith($providerClass, [
            'connection' => $connection,
            'config' => $providerConfig,
        ]);

        if (! $provider instanceof PixProviderInterface) {
            throw new RuntimeException("A classe {$providerClass} não implementa PixProviderInterface.");
        }

        if (! $provider->supports($connection)) {
            throw new RuntimeException('A conexão bancária não possui capacidade PIX habilitada.');
        }

        return $provider;
    }
}
