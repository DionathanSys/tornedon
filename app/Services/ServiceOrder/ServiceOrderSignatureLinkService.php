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

            return $this->persistSignature($serviceOrder, $name, $signature, $request, link: $link);
        });
    }

    public function signInPerson(
        ServiceOrder $serviceOrder,
        string $name,
        string $signature,
        int $signedBy,
        Request $request,
        bool $replace = false,
        ?string $expectedSignatureHash = null,
    ): ServiceOrder {
        return DB::transaction(function () use ($serviceOrder, $name, $signature, $signedBy, $request, $replace, $expectedSignatureHash): ServiceOrder {
            $order = ServiceOrder::query()->where('company_id', $serviceOrder->company_id)->whereKey($serviceOrder->getKey())->lockForUpdate()->first();

            if (! $order) {
                throw new ServiceOrderSignatureException('A ordem de serviço não está mais disponível.', 410);
            }

            if ($order->status === State::CANCELLED) {
                throw new ServiceOrderSignatureException('Não é possível assinar uma ordem de serviço cancelada.');
            }

            $currentHash = filled($order->customer_signature) ? hash('sha256', $order->customer_signature) : null;

            if ($currentHash !== $expectedSignatureHash) {
                throw new ServiceOrderSignatureException('A assinatura da OS mudou. Reabra a coleta para conferir e tentar novamente.', 409);
            }

            if ($currentHash !== null && ! $replace) {
                throw new ServiceOrderSignatureException('Confirme a substituição antes de salvar uma nova assinatura.', 409);
            }

            return $this->persistSignature($order, trim($name), $signature, $request, $signedBy);
        });
    }

    private function persistSignature(ServiceOrder $order, string $name, string $signature, Request $request, ?int $signedBy = null, ?ServiceOrderSignatureLink $link = null): ServiceOrder
    {
        $replacing = filled($order->customer_signature);
        $signedAt = now();
        $metadata = [
            'ip' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 1000, ''),
            'channel' => $link ? 'public_signature_link' : 'operation_panel',
        ];
        $before = [
            'customer_signature_present' => $replacing,
            'customer_signature_hash' => $replacing ? hash('sha256', $order->customer_signature) : null,
            'customer_signed_at' => $order->customer_signed_at?->toIso8601String(),
            'customer_signed_by_name' => $order->customer_signed_by_name,
        ];
        $data = [
            'customer_signature' => $signature,
            'customer_signed_at' => $signedAt,
            'customer_signed_by_name' => $name,
            'customer_signature_metadata' => $metadata,
        ];

        if ($signedBy !== null) {
            $data['updated_by'] = $signedBy;
        }

        $order->forceFill($data)->save();
        $link?->forceFill(['used_at' => $signedAt])->save();

        app(AuditRecorder::class)->recordModelEvent(
            $order,
            $replacing ? 'service_order.customer_signature_replaced' : 'service_order.customer_signed',
            'Ordem de serviço #'.$order->number.($replacing ? ' com assinatura substituída' : ' assinada pelo cliente').($link ? ' via link público' : ' no painel Operation'),
            $before,
            [
                'customer_signature_present' => true,
                'customer_signature_hash' => hash('sha256', $signature),
                'customer_signed_at' => $signedAt->toIso8601String(),
                'customer_signed_by_name' => $name,
            ],
            actorUserId: $signedBy,
            source: $link ? AuditSource::PUBLIC : AuditSource::WEB,
            metadata: $link ? [...$metadata, 'signature_link_id' => $link->id] : $metadata,
        );

        return $order->fresh(['customer', 'company', 'equipment', 'technician', 'supervisor', 'salesperson', 'items.service', 'requisition.items.product']);
    }
}
