<?php

namespace App\Console\Commands;

use App\Services\Audit\ArchiveAuditEntriesService;
use Illuminate\Console\Command;

class ArchiveAuditEntriesCommand extends Command
{
    protected $signature = 'audit:archive-prune {--before=} {--chunk=} {--dry-run}';

    protected $description = 'Arquiva auditorias antigas em JSONL e remove do banco principal.';

    public function handle(ArchiveAuditEntriesService $service): int
    {
        if (! (bool) config('audit.archive.enabled', true)) {
            $this->info('Arquivamento de auditoria desabilitado.');

            return self::SUCCESS;
        }

        $retentionMonths = (int) config('audit.archive.retention_months', 3);
        $beforeOption = $this->option('before');
        $cutoff = $beforeOption
            ? now()->parse((string) $beforeOption)->startOfDay()
            : now()->subMonths($retentionMonths)->startOfDay();

        $chunkSize = $this->option('chunk') ? (int) $this->option('chunk') : null;

        if ($this->option('dry-run')) {
            $count = \App\Models\AuditEntry::query()
                ->where('occurred_at', '<', $cutoff)
                ->count();

            $this->info("Dry-run: {$count} registros seriam arquivados antes de {$cutoff->toDateTimeString()}.");

            return self::SUCCESS;
        }

        $result = $service->archiveOlderThan($cutoff, $chunkSize);

        $this->info("Arquivados: {$result['archived']}");
        $this->info("Removidos do banco: {$result['deleted']}");
        $this->info('Arquivos gerados: ' . count($result['files']));

        return self::SUCCESS;
    }
}
