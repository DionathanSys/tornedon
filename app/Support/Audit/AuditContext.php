<?php

namespace App\Support\Audit;

use App\Enum\Audit\AuditSource;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

class AuditContext
{
    public function __construct(
        public readonly int $companyId,
        public readonly ?int $actorUserId,
        public readonly ?string $actorName,
        public readonly AuditSource $source,
        public readonly array $metadata = [],
    ) {}

    public static function resolve(
        int $companyId,
        ?int $actorUserId = null,
        ?AuditSource $source = null,
        array $metadata = [],
    ): self {
        $resolvedUserId = $source === AuditSource::PUBLIC
            ? null
            : ($actorUserId ?? Auth::id());
        $resolvedSource = $source ?? self::detectSource($resolvedUserId);
        $user = $resolvedUserId ? User::query()->find($resolvedUserId) : null;
        $resolvedMetadata = $metadata;

        if (
            $resolvedUserId !== null
            && $resolvedSource !== AuditSource::WEB
            && ! array_key_exists('triggered_by_user_id', $resolvedMetadata)
        ) {
            $resolvedMetadata['triggered_by_user_id'] = $resolvedUserId;
        }

        $shouldPersistActorUser = in_array($resolvedSource, [AuditSource::WEB, AuditSource::INTEGRATION], true);

        return new self(
            companyId: $companyId,
            actorUserId: $shouldPersistActorUser ? $resolvedUserId : null,
            actorName: $user?->name ?? match ($resolvedSource) {
                AuditSource::WEB => 'Usuário removido',
                AuditSource::PUBLIC => 'Signatário externo',
                default => 'Sistema',
            },
            source: $resolvedSource,
            metadata: $resolvedMetadata,
        );
    }

    private static function detectSource(?int $actorUserId): AuditSource
    {
        if ($actorUserId !== null && ! app()->runningInConsole()) {
            return AuditSource::WEB;
        }

        if (! app()->runningUnitTests() && app()->runningInConsole()) {
            return AuditSource::COMMAND;
        }

        return $actorUserId !== null ? AuditSource::WEB : AuditSource::SYSTEM;
    }
}
