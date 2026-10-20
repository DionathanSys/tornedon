<?php

namespace App\Services\Commitments;

use App\Enum\Commitment\BillingStatus;
use App\Jobs\ProcessCommitmentBillingBatchJob;
use App\Models\CommitmentBillingBatch;
use App\Models\CommitmentBillingBatchItem;
use App\Models\Company;
use App\Models\CustomerCommitment;
use App\Services\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class RequestCommitmentBillingService
{
    public function request(int $companyId, array $commitmentIds, int $userId, bool $emitNfse = false, ?int $serviceId = null): CommitmentBillingBatch
    {
        Validator::make(['records' => $commitmentIds], ['records' => 'required|array|min:1|max:25', 'records.*' => 'required|integer|distinct'])->validate();
        $batch = DB::transaction(function () use ($companyId, $commitmentIds, $userId, $emitNfse, $serviceId) {
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $batch = CommitmentBillingBatch::create(['company_id' => $companyId, 'created_by' => $userId, 'run_token' => (string) Str::uuid(), 'emit_nfse' => $emitNfse]);
            sort($commitmentIds);
            foreach ($commitmentIds as $id) {
                app(CommitmentReviewContext::class)->transaction($companyId, $id, $userId, function (CustomerCommitment $record) use ($batch, $serviceId, $userId) {
                    $billing = $record->billing()->lockForUpdate()->first();
                    if ($record->status->value !== 'approved' || ! $billing || ! $billing->canRequestBilling()) {
                        throw ValidationException::withMessages(['records' => 'Selecione apenas empenhos aprovados e disponíveis para faturamento ou retomada.']);
                    }
                    // Supersede an interrupted attempt. Every financial phase checks the new token.
                    CommitmentBillingBatchItem::forCustomer($record->company_id, $record->customer_id)
                        ->where('commitment_billing_id', $billing->id)->whereIn('status', ['queued', 'processing'])
                        ->update(['status' => 'failed', 'last_error' => 'Tentativa interrompida e substituída por uma retomada.', 'finished_at' => now()]);
                    $token = (string) Str::uuid();
                    $billing->update(['active_batch_id' => $batch->id, 'execution_token' => $token, 'last_error' => null]);
                    $item = CommitmentBillingBatchItem::create([
                        'company_id' => $record->company_id, 'customer_id' => $record->customer_id,
                        'customer_commitment_id' => $record->id,
                        'commitment_billing_batch_id' => $batch->id, 'commitment_billing_id' => $billing->id, 'execution_token' => $token,
                    ]);
                    try {
                        $billing->update(['settings_snapshot' => app(CommitmentBillingData::class)->build($billing, $serviceId)]);
                    } catch (ValidationException $exception) {
                        $message = $exception->validator->errors()->first();
                        $billing->update(['status' => $billing->invoice?->confirmed ? BillingStatus::CONFIRMED : BillingStatus::FAILED, 'last_error' => $message]);
                        $item->update(['status' => 'failed', 'last_error' => $message, 'finished_at' => now()]);
                    }
                    app(AuditRecorder::class)->recordModelEvent($record, 'commitment.billing_requested', 'Faturamento do empenho solicitado.', actorUserId: $userId,
                        metadata: ['batch_id' => $batch->id, 'emit_nfse' => $batch->emit_nfse]);
                });
            }

            return $batch;
        });
        try {
            dispatch(new ProcessCommitmentBillingBatchJob($batch->id, $companyId, $batch->run_token))->afterCommit();
        } catch (Throwable $exception) {
            app(ProcessCommitmentBillingBatchService::class)->fail($batch->id, $companyId, $batch->run_token, 'Não foi possível enviar o lote à fila. Retome os empenhos.');
            report($exception);
        }

        return $batch->refresh();
    }
}
