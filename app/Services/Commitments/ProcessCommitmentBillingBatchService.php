<?php

namespace App\Services\Commitments;

use App\Enum\Commitment\BillingStatus;
use App\Models\CommitmentBilling;
use App\Models\CommitmentBillingBatch;
use App\Models\CommitmentBillingBatchItem;
use App\Models\Company;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class ProcessCommitmentBillingBatchService
{
    public function process(int $batchId, int $companyId, string $runToken): void
    {
        $batch = DB::transaction(function () use ($batchId, $companyId, $runToken) {
            $batch = CommitmentBillingBatch::query()->where('company_id', $companyId)->where('run_token', $runToken)->lockForUpdate()->findOrFail($batchId);
            if ($batch->status !== 'queued') {
                return null;
            }
            $batch->update(['status' => 'processing', 'started_at' => now()]);

            return $batch;
        });
        if (! $batch) {
            return;
        }
        foreach ($batch->items()->where('company_id', $companyId)->orderBy('id')->get() as $item) {
            if (! $this->claim($item)) {
                continue;
            }
            try {
                app(BillCommitmentService::class)->process($item, (int) $batch->created_by, $batch->emit_nfse);
                $this->complete($item);
            } catch (Throwable $exception) {
                $message = $exception instanceof ValidationException ? $exception->validator->errors()->first()
                    : 'Falha no processamento. Consulte o log ou revise a fatura antes de retomar.';
                if (! $exception instanceof ValidationException) {
                    report($exception);
                }
                $this->failItem($item, $message);
            }
        }
        $this->finish($batch);
        if ($batch->createdBy) {
            try {
                $completed = $batch->items()->where('status', 'completed')->count();
                $failed = $batch->items()->where('status', 'failed')->count();
                Notification::make()->title('Lote de empenhos #'.$batch->id.' — '.$batch->statusLabel())
                    ->body("{$completed} concluído(s), {$failed} com falha. Consulte o filtro Lote de faturamento nos empenhos.")
                    ->color($failed ? 'warning' : 'success')->sendToDatabase($batch->createdBy);
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }

    private function claim(CommitmentBillingBatchItem $item): bool
    {
        return DB::transaction(function () use ($item) {
            Company::query()->whereKey($item->company_id)->lockForUpdate()->firstOrFail();
            if ($item->commitment_billing_id === null || $item->status !== 'queued') {
                return false;
            }
            $billing = CommitmentBilling::forCustomer($item->company_id, $item->customer_id)->lockForUpdate()->findOrFail($item->commitment_billing_id);
            $current = CommitmentBillingBatchItem::forCustomer($item->company_id, $item->customer_id)->lockForUpdate()->findOrFail($item->id);
            if ($current->status !== 'queued' || $billing->execution_token !== $item->execution_token) {
                return false;
            }
            $current->update(['status' => 'processing']);
            $billing->update(['attempts' => $billing->attempts + 1, 'processing_started_at' => now()]);

            return true;
        });
    }

    private function complete(CommitmentBillingBatchItem $item): void
    {
        DB::transaction(function () use ($item) {
            Company::query()->whereKey($item->company_id)->lockForUpdate()->firstOrFail();
            $billing = CommitmentBilling::forCustomer($item->company_id, $item->customer_id)->lockForUpdate()->findOrFail($item->commitment_billing_id);
            if ($billing->execution_token !== $item->execution_token) {
                return;
            }
            CommitmentBillingBatchItem::forCustomer($item->company_id, $item->customer_id)->whereKey($item->id)->where('status', 'processing')
                ->update(['status' => 'completed', 'last_error' => null, 'finished_at' => now()]);
        });
    }

    private function failItem(CommitmentBillingBatchItem $item, string $message): void
    {
        if ($item->commitment_billing_id === null) {
            return;
        }
        DB::transaction(function () use ($item, $message) {
            Company::query()->whereKey($item->company_id)->lockForUpdate()->firstOrFail();
            $billing = CommitmentBilling::forCustomer($item->company_id, $item->customer_id)->lockForUpdate()->findOrFail($item->commitment_billing_id);
            if ($billing->execution_token !== $item->execution_token) {
                return;
            }
            $billing->update(['status' => $billing->invoice?->confirmed ? BillingStatus::CONFIRMED : BillingStatus::FAILED, 'last_error' => $message]);
            CommitmentBillingBatchItem::forCustomer($item->company_id, $item->customer_id)->whereKey($item->id)->whereIn('status', ['queued', 'processing'])
                ->update(['status' => 'failed', 'last_error' => $message, 'finished_at' => now()]);
        });
    }

    public function fail(int $batchId, int $companyId, string $runToken, string $message): void
    {
        $batch = CommitmentBillingBatch::query()->where('company_id', $companyId)->where('run_token', $runToken)->find($batchId);
        if (! $batch || in_array($batch->status, ['completed', 'partial_failed', 'failed'], true)) {
            return;
        }
        foreach ($batch->items()->where('company_id', $companyId)->whereIn('status', ['queued', 'processing'])->get() as $item) {
            $this->failItem($item, $message);
        }
        $this->finish($batch);
    }

    private function finish(CommitmentBillingBatch $batch): void
    {
        $completed = $batch->items()->where('status', 'completed')->count();
        $failed = $batch->items()->where('status', 'failed')->count();
        $batch->update(['status' => $failed ? ($completed ? 'partial_failed' : 'failed') : 'completed', 'finished_at' => now()]);
    }
}
