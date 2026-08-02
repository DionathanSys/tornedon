<?php

namespace App\Services\AccountReceivable\Actions\Installment;

use App\Enum\AccountReceivable\Status;
use App\Models\AccountReceivable;
use App\Models\AccountReceivableInstallment;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

class SyncAccountReceivableStatusFromInstallmentsAction
{
    use HandlesActionResponse;

    public function __construct(private readonly AccountReceivable $accountReceivable) {}

    public function execute(): ?AccountReceivable
    {
        try {
            $this->accountReceivable->loadMissing('installments');

            $totalDue = round((float) $this->accountReceivable->installments->sum('due_amount'), 2);
            $totalReceived = round((float) $this->accountReceivable->installments->sum('received_amount'), 2);
            $hasOverdue = $this->accountReceivable->installments
                ->contains(fn (AccountReceivableInstallment $installment) => $installment->balance_amount > 0 && $installment->due_date?->isPast());

            $status = match (true) {
                $totalReceived >= $totalDue && $totalDue > 0 => Status::RECEIVED->value,
                $totalReceived > 0 => Status::PARTIALLY_RECEIVED->value,
                $hasOverdue => Status::OVERDUE->value,
                default => Status::PENDING->value,
            };

            $this->accountReceivable->update([
                'status' => $status,
                'paid' => $status === Status::RECEIVED->value,
                'paid_amount' => $totalReceived,
                'paid_date' => $status === Status::RECEIVED->value
                    ? $this->accountReceivable->installments->max('received_date')
                    : null,
                'due_amount' => $totalDue,
                'due_date' => $this->accountReceivable->installments->min('due_date') ?? $this->accountReceivable->due_date,
            ]);

            $this->setSuccess();

            return $this->accountReceivable->fresh();
        } catch (QueryException $e) {
            $this->setError('Erro ao sincronizar status da conta a receber no banco de dados');

            Log::error($this->getMessage(), [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'error_code' => $this->getErrorCode(),
                'error_message' => $e->getMessage(),
                'account_receivable_id' => $this->accountReceivable->id,
            ]);

            return null;
        } catch (\Exception $e) {
            $this->setError('Erro inesperado ao sincronizar status da conta a receber');

            Log::error($this->getMessage(), [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'error_code' => $this->getErrorCode(),
                'error_message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'account_receivable_id' => $this->accountReceivable->id,
            ]);

            return null;
        }
    }
}
