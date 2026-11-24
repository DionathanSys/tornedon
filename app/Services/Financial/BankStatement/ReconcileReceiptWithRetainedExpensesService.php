<?php

namespace App\Services\Financial\BankStatement;

use App\Models\AccountReceivable;
use App\Models\AccountReceivableInstallment;
use App\Models\BankReconciliationGroup;
use App\Models\BankStatementLine;
use App\Models\CashMovement;
use App\Models\Company;
use App\Models\User;
use App\Services\Access\UserAccessService;
use App\Services\AccountPayable\AccountPayableService;
use App\Services\AccountReceivable\AccountReceivableService;
use App\Services\Audit\AuditRecorder;
use App\Services\Financial\FinancialClassificationService;
use App\Support\Financial\InstallmentPaymentGuard;
use App\Support\Financial\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ReconcileReceiptWithRetainedExpensesService
{
    public static function authorize(int $companyId, int $userId): void
    {
        $user = User::findOrFail($userId);
        $company = Company::findOrFail($companyId);
        $access = app(UserAccessService::class);
        foreach (['cash_movements.access', 'account_receivables.access', 'account_payables.access'] as $permission) {
            abort_unless($access->allows($user, $company, $permission), 403);
        }
    }

    /** Each receipt is assigned to one bank line. Amounts may be decimal strings or integer *_cents. */
    public function reconcile(int $companyId, array $lineIds, array $receipts, string $idempotencyKey, int $userId, ?string $evaluationToken = null): BankReconciliationGroup
    {
        self::authorize($companyId, $userId);
        Validator::make(compact('lineIds', 'receipts', 'idempotencyKey'), [
            'idempotencyKey' => ['required', 'uuid'],
            'lineIds' => ['required', 'array', 'min:1', 'max:100'], 'lineIds.*' => ['required', 'integer', 'min:1', 'distinct'],
            'receipts' => ['required', 'array', 'min:1', 'max:100'],
            'receipts.*.id' => ['required', 'integer', 'min:1'],
            'receipts.*.statement_line_id' => ['nullable', 'integer'],
            'receipts.*.expenses' => ['sometimes', 'array', 'max:100'],
            'receipts.*.expenses.*.financial_category_id' => ['required', 'integer'],
            'receipts.*.expenses.*.supplier_id' => ['nullable', 'integer'],
            'receipts.*.expenses.*.manual_counterparty_name' => ['nullable', 'string', 'max:255'],
            'receipts.*.expenses.*.competence_date' => ['nullable', 'date_format:Y-m-d'],
            'receipts.*.expenses.*.cost_center_id' => ['nullable', 'integer'],
            'receipts.*.expenses.*.result_center_id' => ['nullable', 'integer'],
            'receipts.*.expenses.*.description' => ['nullable', 'string', 'max:255'],
            'receipts.*.notes' => ['nullable', 'string', 'max:10000'],
        ])->validate();
        sort($lineIds, SORT_NUMERIC);
        $receipts = $this->normalize($receipts, $lineIds);
        $hash = hash('sha256', json_encode([$lineIds, $receipts], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($companyId, $lineIds, $receipts, $hash, $idempotencyKey, $userId, $evaluationToken) {
            // All competing confirmations (including the standard reconciliation) lock the bank lines first.
            $lines = BankStatementLine::where('company_id', $companyId)->whereIn('id', $lineIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $this->ensure($lines->count() === count($lineIds), 'Selecione linhas da empresa atual.');
            $existing = BankReconciliationGroup::where('company_id', $companyId)->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
            if ($existing) {
                $this->ensure(hash_equals($existing->request_hash, $hash) && $existing->status === 'confirmed', 'Esta chave já foi usada com outros dados ou em uma operação desfeita.');

                return $existing;
            }
            $accountId = $lines->first()->financial_account_id;
            foreach ($lines as $line) {
                $this->ensure($line->isInflow() && $line->reconciliation_status?->canResolve() && ! $line->hasFinancialEffect()
                    && (int) $line->financial_account_id === (int) $accountId && ! $line->cashMovements()->exists(), 'Selecione depósitos pendentes da mesma conta, sem efeitos financeiros.');
            }
            // Lock parents before installments, matching InstallmentPaymentGuard's ordering.
            $ids = array_column($receipts, 'id');
            $targets = AccountReceivableInstallment::where('company_id', $companyId)->whereIn('id', $ids)->get()->keyBy('id');
            $this->ensure($targets->count() === count(array_unique($ids)), 'Parcela não encontrada na empresa atual.');
            AccountReceivable::where('company_id', $companyId)->whereIn('id', $targets->pluck('account_receivable_id'))->orderBy('id')->lockForUpdate()->get();
            foreach ($targets->sortKeys() as $target) {
                InstallmentPaymentGuard::lock($target);
            }
            $gross = $fees = 0;
            $perLine = array_fill_keys($lineIds, 0);
            foreach ($receipts as $receipt) {
                $gross += $receipt['amount_cents'];
                $expenses = array_sum(array_column($receipt['expenses'], 'amount_cents'));
                $this->ensure($receipt['amount_cents'] > $expenses, 'O recebimento bruto deve superar as despesas retidas.');
                $fees += $expenses;
                $perLine[$receipt['statement_line_id']] += $receipt['amount_cents'] - $expenses;
            }
            $this->ensure($fees > 0, 'Informe ao menos uma despesa retida.');
            foreach ($lines as $line) {
                $this->ensure($perLine[$line->id] === Money::stored($line), 'O valor bruto menos as despesas deve ser exatamente igual a cada depósito do extrato.');
            }
            $group = BankReconciliationGroup::create([
                'company_id' => $companyId, 'operation_type' => BankReconciliationGroup::RETAINED_RECEIPT,
                'status' => 'processing', 'idempotency_key' => $idempotencyKey, 'request_hash' => $hash,
                'created_by' => $userId, 'metadata' => ['gross_cents' => $gross, 'expenses_cents' => $fees, 'net_cents' => $gross - $fees],
            ]);
            $selected = [];
            foreach ($receipts as $receipt) {
                $line = $lines[$receipt['statement_line_id']];
                $target = app(BankStatementCandidateEligibilityService::class)->installmentsForLine($line)->lockForUpdate()->find($receipt['id']);
                $this->ensure($target !== null, 'Parcela indisponível para recebimento.');
                $service = app(AccountReceivableService::class);
                $extra = ['financial_account_id' => $accountId, 'user_id' => $userId, 'origin' => 'bank_statement', 'notes' => $receipt['notes']];
                foreach (['interest_amount', 'fine_amount', 'discount_amount'] as $field) {
                    $extra[$field] = Money::decimal($receipt[$field.'_cents']);
                }
                $payment = $service->registerInstallmentPayment($target, Money::decimal($receipt['amount_cents']), $line->transaction_date->toDateString(), $extra);
                $this->ensure($payment !== null && ! $service->hasError(), $service->getMessageUser() ?: 'Falha ao registrar recebimento.');
                $movement = $this->movement($payment, $companyId, $accountId, $receipt['amount_cents'], 'inflow');
                $component = $group->items()->create([
                    'company_id' => $companyId, 'bank_statement_line_id' => $line->id, 'role' => 'receipt',
                    'receivable_payment_id' => $payment->id, 'cash_movement_id' => $movement->id,
                ]);
                $line->cashMovements()->attach($movement->id, ['amount' => $receipt['amount_cents']]);
                $selected[] = app(BankReconciliationQualityService::class)->target('receivable', $target, $receipt['amount_cents']);
                foreach ($receipt['expenses'] as $expense) {
                    $classification = app(FinancialClassificationService::class);
                    $category = $classification->assertCategoryIsUsable($expense['financial_category_id'], $companyId, 'payable');
                    $this->ensure($category->chart_account_id !== null, 'Vincule a categoria da despesa a uma conta contábil.');
                    $classification->assertChartAccountBelongsToCompany($category->chart_account_id, $companyId);
                    $payables = app(AccountPayableService::class);
                    $document = $payables->create([
                        'company_id' => $companyId, 'supplier_id' => $expense['supplier_id'],
                        'manual_counterparty_name' => $expense['manual_counterparty_name'],
                        'due_date' => $line->transaction_date->toDateString(), 'competence_date' => $expense['competence_date'] ?? $line->transaction_date->toDateString(),
                        'due_amount' => Money::decimal($expense['amount_cents']), 'financial_category_id' => $category->id,
                        'cost_center_id' => $expense['cost_center_id'] ?? $target->cost_center_id,
                        'result_center_id' => $expense['result_center_id'] ?? $target->result_center_id,
                        'description' => $expense['description'] ?: 'Despesa retida no recebimento #'.$payment->id,
                        'is_effective' => true, 'auto_register_payment_on_due_date' => false,
                    ], $userId);
                    $this->ensure($document !== null && ! $payables->hasError(), $payables->getMessageUser() ?: 'Falha ao criar despesa.');
                    $fee = $payables->registerInstallmentPayment($document->installments()->sole(), Money::decimal($expense['amount_cents']), $line->transaction_date->toDateString(), [
                        'financial_account_id' => $accountId, 'origin' => 'bank_statement', 'user_id' => $userId,
                    ]);
                    $this->ensure($fee !== null && ! $payables->hasError(), $payables->getMessageUser() ?: 'Falha ao liquidar despesa.');
                    $outflow = $this->movement($fee, $companyId, $accountId, $expense['amount_cents'], 'outflow');
                    $group->items()->create([
                        'company_id' => $companyId, 'bank_statement_line_id' => $line->id, 'role' => 'retained_expense',
                        'receipt_item_id' => $component->id, 'payable_payment_id' => $fee->id,
                        'cash_movement_id' => $outflow->id, 'created_document' => true,
                    ]);
                    $line->cashMovements()->attach($outflow->id, ['amount' => $expense['amount_cents']]);
                }
            }
            foreach ($lines as $line) {
                $metadata = $line->metadata ?? [];
                if (isset($metadata['decision'])) {
                    $metadata['decision_history'][] = $metadata['decision'];
                }
                $metadata['decision'] = ['type' => 'retained_receipt', 'group_id' => $group->id, 'resolved_by' => $userId, 'resolved_at' => now()->toDateTimeString()];
                $line->update(['bank_reconciliation_group_id' => $group->id, 'reconciliation_status' => 'reconciled', 'reconciled_at' => now(), 'metadata' => $metadata]);
            }
            $group->update(['status' => 'confirmed', 'confirmed_at' => now()]);
            app(BankReconciliationQualityService::class)->confirm($lines->values(), $selected, $userId, $group->id, $evaluationToken);
            app(AuditRecorder::class)->recordModelEvent($group, 'retained_receipt.created', 'Recebimento com despesas retidas conciliado', actorUserId: $userId, metadata: ['components' => $group->items()->get()->toArray()]);

            return $group;
        }, 3);
    }

    private function normalize(array $receipts, array $lineIds): array
    {
        $normalized = [];
        $keys = [];
        foreach ($receipts as $receipt) {
            $lineId = (int) ($receipt['statement_line_id'] ?? (count($lineIds) === 1 ? $lineIds[0] : 0));
            $key = $lineId.':'.(int) $receipt['id'];
            $this->ensure(in_array($lineId, $lineIds, true) && ! isset($keys[$key]), 'Atribua cada parcela uma única vez a cada depósito.');
            $keys[$key] = true;
            $row = ['id' => (int) $receipt['id'], 'statement_line_id' => $lineId, 'notes' => $receipt['notes'] ?? '', 'expenses' => []];
            foreach (['amount', 'interest_amount', 'fine_amount', 'discount_amount'] as $field) {
                $row[$field.'_cents'] = $this->inputCents($receipt, $field);
            }
            foreach ($receipt['expenses'] ?? [] as $expense) {
                $amount = $this->inputCents($expense, 'amount');
                $this->ensure($amount > 0, 'Informe um valor positivo para cada despesa.');
                $row['expenses'][] = [
                    'amount_cents' => $amount, 'financial_category_id' => (int) $expense['financial_category_id'],
                    'supplier_id' => filled($expense['supplier_id'] ?? null) ? (int) $expense['supplier_id'] : null,
                    'manual_counterparty_name' => filled($expense['supplier_id'] ?? null) ? null : ($expense['manual_counterparty_name'] ?? null),
                    'competence_date' => filled($expense['competence_date'] ?? null) ? $expense['competence_date'] : null,
                    'cost_center_id' => filled($expense['cost_center_id'] ?? null) ? (int) $expense['cost_center_id'] : null,
                    'result_center_id' => filled($expense['result_center_id'] ?? null) ? (int) $expense['result_center_id'] : null,
                    'description' => $expense['description'] ?? '',
                ];
            }
            $this->ensure($row['amount_cents'] > 0, 'Informe um recebimento positivo.');
            $normalized[] = $row;
        }
        usort($normalized, fn ($a, $b) => [$a['statement_line_id'], $a['id']] <=> [$b['statement_line_id'], $b['id']]);

        return $normalized;
    }

    private function inputCents(array $input, string $field): int
    {
        $value = $input[$field.'_cents'] ?? null;
        if (array_key_exists($field.'_cents', $input)) {
            $this->ensure(is_int($value) && $value >= 0 && $value <= 99999999999999, 'Informe centavos inteiros válidos.');

            return $value;
        }
        $cents = Money::cents($input[$field] ?? '0.00', true);
        $this->ensure($cents >= 0, 'Não use valores negativos.');

        return $cents;
    }

    private function movement($payment, int $companyId, int $accountId, int $amount, string $direction): CashMovement
    {
        $movement = CashMovement::where('origin_type', $payment::class)->where('origin_id', $payment->id)->lockForUpdate()->first();
        $this->ensure($movement && (int) $movement->company_id === $companyId && (int) $movement->financial_account_id === $accountId
            && $movement->direction->value === $direction && Money::stored($movement) === $amount && ! $movement->hasStatementLinks()
            && ! $movement->reversed_at && ! $movement->reversal_of_id, 'Movimento financeiro da operação incompatível ou já conciliado.');

        return $movement;
    }

    private function ensure(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['retained_expenses' => $message]);
        }
    }
}
