<?php

namespace App\Services\AccountReceivable\Actions\Installment;

use App\Enum\AccountReceivable\Status;
use App\Models\AccountReceivable;
use App\Models\AccountReceivableInstallment;
use App\Support\Financial\Money;
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
            $this->accountReceivable->refresh();
            if ($this->accountReceivable->status === Status::CANCELLED) {
                $this->setSuccess();

                return $this->accountReceivable;
            }
            $this->accountReceivable->load('installments');

            $totalDue = Money::sum($this->accountReceivable->installments, 'due_amount');
            $totalReceived = Money::sum($this->accountReceivable->installments, 'received_amount');
            $hasOverdue = $this->accountReceivable->installments
                ->contains(fn (AccountReceivableInstallment $installment) => $installment->status !== Status::CANCELLED && $installment->balance_amount > 0 && $installment->due_date?->lt(today()));

            $status = match (true) {
                $totalReceived >= $totalDue && $totalDue > 0 => Status::RECEIVED->value,
                $totalReceived > 0 => Status::PARTIALLY_RECEIVED->value,
                $hasOverdue => Status::OVERDUE->value,
                default => Status::PENDING->value,
            };

            $this->accountReceivable->update([
                'status' => $status,
                'paid' => $status === Status::RECEIVED->value,
                'paid_amount' => Money::decimal($totalReceived),
                'paid_date' => $status === Status::RECEIVED->value
                    ? $this->accountReceivable->installments->max('received_date')
                    : null,
                'due_amount' => Money::decimal($totalDue),
                'due_date' => $this->accountReceivable->installments->min('due_date') ?? $this->accountReceivable->due_date,
            ]);

            $this->setSuccess();

            return $this->accountReceivable->fresh();
        } catch (QueryException $e) {
            $this->setError('Erro ao sincronizar status da conta a receber no banco de dados');

            Log::error($this->getMessage(), [
                'metodo' => __METHOD__.'@'.__LINE__,
                'error_code' => $this->getErrorCode(),
                'error_message' => $e->getMessage(),
                'account_receivable_id' => $this->accountReceivable->id,
            ]);

            return null;
        } catch (\Exception $e) {
            $this->setError('Erro inesperado ao sincronizar status da conta a receber');

            Log::error($this->getMessage(), [
                'metodo' => __METHOD__.'@'.__LINE__,
                'error_code' => $this->getErrorCode(),
                'error_message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'account_receivable_id' => $this->accountReceivable->id,
            ]);

            return null;
        }
    }
}
