<?php

namespace App\Services\Financial\Pix;

use App\Enum\Financial\PixChargeStatus;
use App\Models\PixCharge;
use Illuminate\Support\Facades\URL;

final class PixChargePublicLinkService
{
    /**
     * @return array{url: string, expires_at: \Illuminate\Support\Carbon}
     */
    public function generate(PixCharge $charge): array
    {
        if ($charge->status !== PixChargeStatus::REGISTERED) {
            throw new \InvalidArgumentException('O link público só pode ser gerado para cobranças PIX registradas.');
        }

        $expiresAt = now()->addMinutes(max(1, (int) config('pix.public_link_ttl_minutes', 60)));

        return [
            'url' => URL::temporarySignedRoute(
                'pix-charges.public.show',
                $expiresAt,
                ['pixCharge' => $charge->getKey()],
            ),
            'expires_at' => $expiresAt,
        ];
    }
}
