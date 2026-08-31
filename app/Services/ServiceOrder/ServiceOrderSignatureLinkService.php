<?php

namespace App\Services\ServiceOrder;

use App\Enum\Audit\AuditSource;
use App\Enum\ServiceOrder\State;
use App\Exceptions\ServiceOrderSignatureException;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderSignatureLink;
use App\Services\Audit\AuditRecorder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ServiceOrderSignatureLinkService
{
    public function create(ServiceOrder $serviceOrder, int $createdBy): array
    {
        if (filled($serviceOrder->customer_signature)) {
            throw new ServiceOrderSignatureException(
                'Esta ordem de serviço já possui uma assinatura.',
                409,
            );
        }

        if ($serviceOrder->status === State::CANCELLED) {
            throw new ServiceOrderSignatureException(
                'Não é possível gerar um link para uma ordem de serviço cancelada.',
                422,
            );
        }

        return DB::transaction(function () use ($serviceOrder, $createdBy): array {
            $serviceOrder->signatureLinks()
                ->whereNull('used_at')
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            $token = bin2hex(random_bytes(32));
            $expiresAt = now()->addDays(7);

            $link = $serviceOrder->signatureLinks()->create([
                'token_hash' => hash('sha256', $token),
                'expires_at' => $expiresAt,
                'created_by' => $createdBy,
            ]);

            return [
                'link' => $link,
                'token' => $token,
                'url' => route('service-orders.signature.show', ['token' => $token]),
                'expires_at' => $expiresAt,
            ];
        });
    }

    public function findByToken(string $token): ?ServiceOrderSignatureLink
    {
        return ServiceOrderSignatureLink::query()
            ->where('token_hash', hash('sha256', $token))
            ->first();
    }

    public function sign(string $token, string $name, string $signature, Request $request): ServiceOrder
    {
        return DB::transaction(function () use ($token, $name, $signature, $request): ServiceOrder {
            $link = ServiceOrderSignatureLink::query()
                ->where('token_hash', hash('sha256', $token))
                ->lockForUpdate()
                ->first();

            if ($link === null) {
                throw new ServiceOrderSignatureException(
                    'Este link de assinatura expirou ou não está mais disponível.',
                    410,
                );
            }

            if ($link->used_at !== null) {
                throw new ServiceOrderSignatureException(
                    'Esta ordem de serviço já foi assinada.',
                    409,
                );
            }

            if (! $link->isUsable()) {
                throw new ServiceOrderSignatureException(
                    'Este link de assinatura expirou ou não está mais disponível.',
                    410,
                );
            }

            $serviceOrder = ServiceOrder::query()
                ->lockForUpdate()
                ->find($link->service_order_id);

            if ($serviceOrder === null) {
                throw new ServiceOrderSignatureException(
                    'A ordem de serviço não está mais disponível.',
                    410,
                );
            }

            if ($serviceOrder->status === State::CANCELLED) {
                throw new ServiceOrderSignatureException(
                    'Não é possível assinar uma ordem de serviço cancelada.',
                    410,
                );
            }

            if (filled($serviceOrder->customer_signature)) {
                throw new ServiceOrderSignatureException(
                    'Esta ordem de serviço já foi assinada.',
                    409,
                );
            }

            $signedAt = now();
            $metadata = [
                'ip' => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 1000, ''),
                'channel' => 'public_signature_link',
            ];

            $before = [
                'customer_signature_present' => filled($serviceOrder->customer_signature),
                'customer_signed_at' => $serviceOrder->customer_signed_at?->toIso8601String(),
                'customer_signed_by_name' => $serviceOrder->customer_signed_by_name,
            ];

            $serviceOrder->forceFill([
                'customer_signature' => $signature,
                'customer_signed_at' => $signedAt,
                'customer_signed_by_name' => $name,
                'customer_signature_metadata' => $metadata,
            ])->save();

            $link->forceFill(['used_at' => $signedAt])->save();

            app(AuditRecorder::class)->recordModelEvent(
                $serviceOrder,
                'service_order.customer_signed',
                "Ordem de serviço #{$serviceOrder->number} assinada pelo cliente via link público",
                $before,
                [
                    'customer_signature_present' => true,
                    'customer_signed_at' => $signedAt->toIso8601String(),
                    'customer_signed_by_name' => $name,
                ],
                source: AuditSource::PUBLIC,
                metadata: [
                    ...$metadata,
                    'signature_link_id' => $link->id,
                ],
            );

            return $serviceOrder->fresh([
                'customer',
                'company',
                'equipment',
                'technician',
                'supervisor',
                'salesperson',
                'items.service',
                'requisition.items.product',
            ]);
        });
    }
}
