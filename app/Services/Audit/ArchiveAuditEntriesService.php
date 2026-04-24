<?php

namespace App\Services\Audit;

use App\Models\AuditEntry;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class ArchiveAuditEntriesService
{
    /**
     * @return array{archived:int, deleted:int, files:list<string>}
     */
    public function archiveOlderThan(CarbonInterface $cutoff, ?int $chunkSize = null): array
    {
        $disk = (string) config('audit.archive.disk', 'local');
        $basePath = trim((string) config('audit.archive.path', 'audit-archives'), '/');
        $chunkSize ??= (int) config('audit.archive.chunk_size', 1000);

        $archived = 0;
        $deleted = 0;
        $files = [];

        AuditEntry::query()
            ->where('occurred_at', '<', $cutoff)
            ->orderBy('id')
            ->chunkById($chunkSize, function (Collection $entries) use ($disk, $basePath, &$archived, &$deleted, &$files): void {
                if ($entries->isEmpty()) {
                    return;
                }

                $groupedEntries = $entries->groupBy(function (AuditEntry $entry): string {
                    return implode('|', [
                        (string) $entry->company_id,
                        $entry->occurred_at?->format('Y') ?? now()->format('Y'),
                        $entry->occurred_at?->format('m') ?? now()->format('m'),
                    ]);
                });

                foreach ($groupedEntries as $group) {
                    /** @var Collection<int, AuditEntry> $group */
                    $first = $group->first();

                    if (! $first) {
                        continue;
                    }

                    $path = sprintf(
                        '%s/%s/%s/company_%d/audit_%d_%d.jsonl',
                        $basePath,
                        $first->occurred_at?->format('Y') ?? now()->format('Y'),
                        $first->occurred_at?->format('m') ?? now()->format('m'),
                        $first->company_id,
                        $group->min('id'),
                        $group->max('id'),
                    );

                    $payload = $group
                        ->map(fn (AuditEntry $entry): string => json_encode([
                            'id' => $entry->id,
                            'company_id' => $entry->company_id,
                            'auditable_type' => $entry->auditable_type,
                            'auditable_id' => $entry->auditable_id,
                            'actor_user_id' => $entry->actor_user_id,
                            'actor_name' => $entry->actor_name,
                            'source' => $entry->source?->value ?? (string) $entry->source,
                            'event' => $entry->event,
                            'action' => $entry->action,
                            'summary' => $entry->summary,
                            'before' => $entry->before,
                            'after' => $entry->after,
                            'diff' => $entry->diff,
                            'metadata' => $entry->metadata,
                            'occurred_at' => $entry->occurred_at?->toISOString(),
                            'created_at' => $entry->created_at?->toISOString(),
                            'updated_at' => $entry->updated_at?->toISOString(),
                        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
                        ->implode(PHP_EOL) . PHP_EOL;

                    Storage::disk($disk)->put($path, $payload);

                    $files[] = $path;
                    $archived += $group->count();
                }

                $ids = $entries->modelKeys();
                $deleted += AuditEntry::query()->whereKey($ids)->delete();
            });

        return [
            'archived' => $archived,
            'deleted' => $deleted,
            'files' => array_values(array_unique($files)),
        ];
    }
}
