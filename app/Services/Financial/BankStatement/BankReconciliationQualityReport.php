<?php

namespace App\Services\Financial\BankStatement;

use App\Models\BankReconciliationDecision;
use App\Models\BankReconciliationEvaluation;
use Illuminate\Database\Eloquent\Builder;

class BankReconciliationQualityReport
{
    public function decisions(int $companyId, ?int $importId = null): Builder
    {
        return BankReconciliationDecision::query()->where('company_id', $companyId)
            ->when($importId, fn ($query) => $query->where('import_id', $importId))
            ->where('confirmed_at', '>=', now()->subDays(90));
    }

    public function summary(int $companyId, ?int $importId = null): array
    {
        $evaluations = BankReconciliationEvaluation::query()->where('company_id', $companyId)
            ->when($importId, fn ($query) => $query->where('import_id', $importId))
            ->where('created_at', '>=', now()->subDays(90));
        $decisions = $this->decisions($companyId, $importId);
        $singleReviewed = (clone $decisions)->whereNotNull('evaluation_id')->where('single_target', true)
            ->whereHas('evaluation', fn ($query) => $query->where('complete', true));
        $reviewedCount = (clone $singleReviewed)->count();
        $confirmedCount = (clone $decisions)->count();
        $reversedCount = (clone $decisions)->whereNotNull('reversed_at')->count();
        $coveredLines = [];
        foreach ((clone $evaluations)->where('complete', true)->orderBy('id')->lazyById(200) as $evaluation) {
            $covered = [];
            foreach ($evaluation->recommendations['records'] as $record) {
                $covered = [...$covered, ...$record['statement_line_ids']];
            }
            if ($evaluation->recommendations['bundles'] !== []) {
                $covered = $evaluation->line_ids;
            }
            foreach ($evaluation->line_ids as $lineId) {
                $coveredLines[$lineId] = in_array($lineId, $covered, true);
            }
        }

        return [
            'attempts' => (clone $evaluations)->count(),
            'incomplete_attempts' => (clone $evaluations)->where('complete', false)->count(),
            'evaluated_lines' => count($coveredLines),
            'covered_lines' => count(array_filter($coveredLines)),
            'coverage_percent' => $this->percent(count(array_filter($coveredLines)), count($coveredLines)),
            'reviewed_single_decisions' => $reviewedCount,
            'top1_percent' => $this->percent((clone $singleReviewed)->whereNull('reversed_at')->where('individual_rank', 1)->count(), $reviewedCount),
            'top3_percent' => $this->percent((clone $singleReviewed)->whereNull('reversed_at')->whereBetween('individual_rank', [1, 3])->count(), $reviewedCount),
            'explicit_rejections' => (clone $evaluations)->whereNotNull('rejected_at')->count(),
            'rejection_percent' => $this->percent((clone $evaluations)->whereNotNull('rejected_at')->count(), (clone $evaluations)->where('has_suggestions', true)->count()),
            'confirmed_decisions' => $confirmedCount,
            'reversed_decisions' => $reversedCount,
            'reversal_percent' => $this->percent($reversedCount, $confirmedCount),
            'without_evaluation' => (clone $decisions)->whereNull('evaluation_id')->count(),
            'bundle_matches' => (clone $decisions)->whereNull('reversed_at')->whereNotNull('bundle_rank')->count(),
            'outside_suggestions' => (clone $decisions)->whereNotNull('evaluation_id')->whereNull('individual_rank')->whereNull('bundle_rank')
                ->whereHas('evaluation', fn ($query) => $query->where('has_suggestions', true))->count(),
            'average_duration_ms' => (clone $evaluations)->avg('duration_ms'),
            'recent' => (clone $decisions)->latest('id')->limit(10)->get(),
        ];
    }

    private function percent(int $numerator, int $denominator): ?float
    {
        return $denominator > 0 ? round($numerator * 100 / $denominator, 1) : null;
    }
}
