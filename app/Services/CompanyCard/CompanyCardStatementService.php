<?php

namespace App\Services\CompanyCard;

use App\Enum\CompanyCard\StatementStatus;
use App\Models\CompanyCardStatement;
use App\Models\CompanyCardStatementItem;
use App\Models\CompanyCardTransaction;
use App\Models\CompanyCreditCard;
use App\Services\AccountPayable\AccountPayableService;
use App\Services\Audit\AuditRecorder;
use App\Traits\HandlesServiceResponse;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CompanyCardStatementService
{
    use HandlesServiceResponse;

    public function __construct(
        private readonly AccountPayableService $accountPayableService = new AccountPayableService(),
    ) {}

    public function resolveReferenceMonth(CompanyCreditCard $card, Carbon|string $transactionDate): Carbon
    {
        $date = Carbon::parse($transactionDate);
        $monthRef = $date->copy()->startOfMonth();
        $cutoff = $this->resolveCutoffDate($card, $monthRef);

        if ($date->toDateString() <= $cutoff->toDateString()) {
            return $monthRef;
        }

        return $monthRef->copy()->addMonth()->startOfMonth();
    }

    public function resolveCutoffDate(CompanyCreditCard $card, Carbon|string $referenceMonth): Carbon
    {
        $closingDate = $this->resolveClosingDate($card, $referenceMonth);
        $daysToSubtract = max(0, (int) $card->statement_cutoff_business_days);

        return $this->subtractBusinessDays($closingDate, $daysToSubtract);
    }

    public function resolveClosingDate(CompanyCreditCard $card, Carbon|string $referenceMonth): Carbon
    {
        $month = Carbon::parse($referenceMonth)->startOfMonth();
        $day = min((int) $card->closing_day, $month->copy()->endOfMonth()->day);

        return $month->copy()->day($day);
    }

    /**
     * @return CompanyCardStatement|null
     */
    public function generateStatement(CompanyCreditCard $card, Carbon|string $referenceMonth): ?CompanyCardStatement
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($card, $referenceMonth) {
                $reference = Carbon::parse($referenceMonth)->startOfMonth();
                $closingDate = $this->resolveClosingDate($card, $reference);
                $cutoffDate = $this->resolveCutoffDate($card, $reference);
                $previousCutoffDate = $this->resolveCutoffDate($card, $reference->copy()->subMonth());
                $periodStart = $previousCutoffDate->copy()->addDay();
                $periodEnd = $cutoffDate->copy();
                $dueDate = $this->resolveDueDate($card, $reference);

                $statement = CompanyCardStatement::query()->firstOrCreate(
                    [
                        'company_id' => $card->company_id,
                        'company_credit_card_id' => $card->id,
                        'reference_month' => $reference->toDateString(),
                    ],
                    [
                        'period_start' => $periodStart->toDateString(),
                        'period_end' => $periodEnd->toDateString(),
                        'cutoff_date' => $cutoffDate->toDateString(),
                        'closing_date' => $closingDate->toDateString(),
                        'due_date' => $dueDate->toDateString(),
                        'gross_total' => 0,
                        'fees_total' => 0,
                        'net_total' => 0,
                        'paid_total' => 0,
                        'balance_total' => 0,
                        'status' => StatementStatus::OPEN->value,
                    ]
                );

                if ($statement->wasRecentlyCreated === false) {
                    $statement->update([
                        'period_start' => $periodStart->toDateString(),
                        'period_end' => $periodEnd->toDateString(),
                        'cutoff_date' => $cutoffDate->toDateString(),
                        'closing_date' => $closingDate->toDateString(),
                        'due_date' => $dueDate->toDateString(),
                    ]);
                }

                $this->attachEligibleTransactions($statement);
                $statement = $this->recalculateTotals($statement);

                $this->setSuccess('Fatura do cartão gerada/atualizada com sucesso.');

                return $statement;
            });
        } catch (\Throwable $e) {
            $this->setError('Erro ao gerar fatura do cartão corporativo.');
            return null;
        }
    }

    public function closeStatement(CompanyCardStatement $statement, int $userId, array $extra = []): ?CompanyCardStatement
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($statement, $userId, $extra) {
                $statement->loadMissing('companyCreditCard', 'items', 'transactions');

                $statement = $this->generateStatement($statement->companyCreditCard, (string) $statement->reference_month)?->fresh() ?? $statement->fresh();

                if (! $statement || $statement->items()->count() <= 0) {
                    throw ValidationException::withMessages([
                        'company_card_statement_id' => ['Não é possível fechar fatura sem transações válidas.'],
                    ]);
                }

                if ($statement->account_payable_id) {
                    throw ValidationException::withMessages([
                        'account_payable_id' => ['A fatura já possui conta a pagar vinculada.'],
                    ]);
                }

                $card = $statement->companyCreditCard;

                if (! $card->issuer_partner_id) {
                    throw ValidationException::withMessages([
                        'issuer_partner_id' => ['Defina o parceiro emissor do cartão para gerar a obrigação da fatura.'],
                    ]);
                }

                $payable = $this->accountPayableService->create([
                    'supplier_id' => $card->issuer_partner_id,
                    'company_id' => $statement->company_id,
                    'fiscal_document_id' => null,
                    'due_date' => $statement->due_date?->toDateString(),
                    'due_amount' => (float) $statement->net_total,
                    'description' => $extra['description'] ?? $this->defaultPayableDescription($statement),
                    'document_number' => $extra['document_number'] ?? $this->defaultPayableDocument($statement),
                    'payment_method' => 'cartao_credito',
                    'financial_category_id' => $extra['financial_category_id'] ?? null,
                    'cost_center_id' => $extra['cost_center_id'] ?? null,
                    'installment_count' => 1,
                    'amount_input_mode' => 'total',
                    'auto_payment_financial_account_id' => $card->default_financial_account_id,
                ], $userId);

                if ($this->accountPayableService->hasError() || ! $payable) {
                    $this->setError(
                        $this->accountPayableService->getMessage(),
                        $this->accountPayableService->getErrors(),
                        422,
                        $this->accountPayableService->getErrorCode()
                    );

                    return null;
                }

                $before = app(AuditRecorder::class)->snapshot($statement);

                $statement->update([
                    'account_payable_id' => $payable->id,
                    'status' => StatementStatus::CLOSED->value,
                    'closed_at' => now(),
                ]);

                $audit = app(AuditRecorder::class);
                $audit->recordModelEvent(
                    $statement->fresh(),
                    'company_card_statement.closed',
                    'Fatura de cartão corporativo fechada',
                    $before,
                    $audit->snapshot($statement->fresh()),
                    $userId,
                    null,
                    ['account_payable_id' => $payable->id],
                );

                $this->setSuccess('Fatura do cartão fechada com sucesso.');

                return $statement->fresh();
            });
        } catch (ValidationException $e) {
            $this->setError('Falha de validação dos dados.', $e->errors(), 422);
            return null;
        } catch (\Throwable $e) {
            $this->setError('Erro ao fechar fatura do cartão corporativo.');
            return null;
        }
    }

    public function recalculateTotals(CompanyCardStatement $statement): CompanyCardStatement
    {
        $statement->loadMissing('items', 'payments');

        $grossTotal = round((float) $statement->items->sum(fn (CompanyCardStatementItem $item) => (float) $item->amount_allocated), 2);
        $feesTotal = round((float) $statement->fees_total, 2);
        $netTotal = round($grossTotal + $feesTotal, 2);
        $paidTotal = round((float) $statement->payments->sum(fn ($payment) => (float) $payment->amount), 2);
        $balanceTotal = max(round($netTotal - $paidTotal, 2), 0);

        $currentStatus = $statement->status instanceof StatementStatus
            ? $statement->status
            : StatementStatus::tryFrom((string) $statement->status);

        if ($balanceTotal <= 0 && $paidTotal > 0) {
            $nextStatus = StatementStatus::PAID;
        } elseif ($paidTotal > 0) {
            $nextStatus = StatementStatus::PARTIAL;
        } elseif ($currentStatus === StatementStatus::CANCELED) {
            $nextStatus = StatementStatus::CANCELED;
        } elseif ($statement->account_payable_id || $statement->closed_at) {
            $nextStatus = StatementStatus::CLOSED;
        } else {
            $nextStatus = StatementStatus::OPEN;
        }

        $statement->update([
            'gross_total' => $grossTotal,
            'net_total' => $netTotal,
            'paid_total' => $paidTotal,
            'balance_total' => $balanceTotal,
            'status' => $nextStatus->value,
            'paid_at' => $nextStatus === StatementStatus::PAID ? now() : null,
        ]);

        return $statement->fresh();
    }

    private function attachEligibleTransactions(CompanyCardStatement $statement): void
    {
        $referenceMonth = $statement->reference_month?->copy()->startOfMonth()->toDateString();

        $eligible = CompanyCardTransaction::query()
            ->where('company_id', $statement->company_id)
            ->where('company_credit_card_id', $statement->company_credit_card_id)
            ->whereDate('statement_reference_month', $referenceMonth)
            ->whereIn('status', ['pending', 'posted', 'allocated'])
            ->whereDoesntHave('statementItems')
            ->get();

        foreach ($eligible as $transaction) {
            CompanyCardStatementItem::query()->create([
                'company_card_statement_id' => $statement->id,
                'company_card_transaction_id' => $transaction->id,
                'amount_allocated' => (float) $transaction->amount,
            ]);

            $transaction->update(['status' => 'allocated']);
        }
    }

    private function subtractBusinessDays(Carbon $date, int $businessDays): Carbon
    {
        $current = $date->copy();

        for ($i = 0; $i < $businessDays; $i++) {
            do {
                $current->subDay();
            } while ($current->isWeekend());
        }

        return $current;
    }

    private function resolveDueDate(CompanyCreditCard $card, Carbon $referenceMonth): Carbon
    {
        $dueMonth = $referenceMonth->copy()->addMonth();
        $day = min((int) $card->due_day, $dueMonth->copy()->endOfMonth()->day);

        return $dueMonth->day($day);
    }

    private function defaultPayableDescription(CompanyCardStatement $statement): string
    {
        $ref = $statement->reference_month?->format('m/Y') ?? '-';
        $cardName = $statement->companyCreditCard?->name ?? 'Cartão corporativo';

        return sprintf('Fatura %s - competência %s', $cardName, $ref);
    }

    private function defaultPayableDocument(CompanyCardStatement $statement): string
    {
        return sprintf(
            'CC-%d-%s',
            (int) $statement->company_credit_card_id,
            $statement->reference_month?->format('Ym') ?? now()->format('Ym')
        );
    }
}
