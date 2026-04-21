<?php

namespace App\Services\Audit;

use App\Enum\Audit\AuditSource;
use App\Models\AuditEntry;
use App\Support\Audit\AuditContext;
use App\Support\Audit\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class AuditRecorder
{
    /**
     * @return array<string, mixed>
     */
    public function snapshot(Model $model, array $only = []): array
    {
        $attributes = $model->attributesToArray();

        if ($only !== []) {
            $attributes = Arr::only($attributes, $only);
        }

        unset($attributes['updated_at']);

        return AuditLog::payload($attributes, array_keys($attributes));
    }

    public function recordModelEvent(
        Model $model,
        string $event,
        string $summary,
        ?array $before = null,
        ?array $after = null,
        ?int $actorUserId = null,
        ?AuditSource $source = null,
        array $metadata = [],
    ): ?AuditEntry {
        $companyId = (int) ($model->getAttribute('company_id') ?? 0);

        if ($companyId <= 0) {
            throw new \RuntimeException('Não foi possível resolver company_id para a auditoria.');
        }

        $context = AuditContext::resolve(
            companyId: $companyId,
            actorUserId: $actorUserId,
            source: $source,
            metadata: $metadata,
        );

        $resolvedBefore = $before;
        $resolvedAfter = $after;

        if ($resolvedBefore === null && $resolvedAfter === null) {
            $resolvedAfter = $this->snapshot($model);
        }

        $diff = AuditLog::diff($resolvedBefore ?? [], $resolvedAfter ?? []);
        $action = Str::afterLast($event, '.');

        if ($action === 'updated' && $diff === []) {
            return null;
        }

        return AuditEntry::query()->create([
            'company_id' => $context->companyId,
            'auditable_type' => $model->getMorphClass(),
            'auditable_id' => $model->getKey(),
            'actor_user_id' => $context->actorUserId,
            'actor_name' => $context->actorName,
            'source' => $context->source->value,
            'event' => $event,
            'action' => $action,
            'summary' => $summary,
            'before' => $resolvedBefore,
            'after' => $resolvedAfter,
            'diff' => $diff === [] ? null : $diff,
            'metadata' => array_filter([
                ...$context->metadata,
                ...$metadata,
                'entity_label' => AuditEntry::resolveAuditableTypeLabel($model->getMorphClass()),
                'record_identifier' => $this->resolveRecordIdentifier($model),
            ], static fn (mixed $value): bool => $value !== null && $value !== ''),
            'occurred_at' => now(),
        ]);
    }

    private function resolveRecordIdentifier(Model $model): ?string
    {
        foreach (['number', 'production_order_number', 'invoice_number', 'document_number', 'sequence_number', 'name'] as $attribute) {
            $value = $model->getAttribute($attribute);

            if ($value !== null && $value !== '') {
                return (string) $value;
            }
        }

        return (string) $model->getKey();
    }
}
