<?php

namespace App\Console\Commands;

use App\Enum\Email\EmailDispatchStatus;
use App\Models\EmailDispatch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class AlertEmailDispatchFailuresCommand extends Command
{
    protected $signature = 'emails:alert-failures';

    protected $description = 'Verifica falhas recorrentes em envios de e-mail e registra alertas operacionais.';

    public function handle(): int
    {
        if (! (bool) config('email_notifications.alerts.enabled', true)) {
            return self::SUCCESS;
        }

        $windowMinutes = (int) config('email_notifications.alerts.window_minutes', 30);
        $minDispatches = (int) config('email_notifications.alerts.min_dispatches', 10);
        $failureRateThreshold = (float) config('email_notifications.alerts.failure_rate_threshold', 0.10);

        $from = now()->subMinutes(max(1, $windowMinutes));

        $base = EmailDispatch::query()->where('created_at', '>=', $from);
        $total = (clone $base)->count();
        if ($total < $minDispatches) {
            return self::SUCCESS;
        }

        $failed = (clone $base)
            ->whereIn('status', [
                EmailDispatchStatus::FAILED->value,
                EmailDispatchStatus::DEAD_LETTER->value,
            ])
            ->count();

        $failureRate = $total > 0 ? ($failed / $total) : 0.0;

        if ($failureRate >= $failureRateThreshold) {
            $recurring = (clone $base)
                ->selectRaw('company_id, document_type, event, count(*) as total, sum(case when status in (?, ?) then 1 else 0 end) as failed', [
                    EmailDispatchStatus::FAILED->value,
                    EmailDispatchStatus::DEAD_LETTER->value,
                ])
                ->groupBy(['company_id', 'document_type', 'event'])
                ->orderByDesc('failed')
                ->limit(5)
                ->get()
                ->map(fn ($row): array => [
                    'company_id' => (int) $row->company_id,
                    'document_type' => (string) $row->document_type,
                    'event' => (string) $row->event,
                    'total' => (int) $row->total,
                    'failed' => (int) $row->failed,
                ])
                ->all();

            Log::warning('AlertEmailDispatchFailuresCommand: taxa de falha acima do limite', [
                'window_minutes' => $windowMinutes,
                'total_dispatches' => $total,
                'failed_dispatches' => $failed,
                'failure_rate' => round($failureRate, 4),
                'threshold' => $failureRateThreshold,
                'top_recurring_failures' => $recurring,
            ]);
        }

        return self::SUCCESS;
    }
}

