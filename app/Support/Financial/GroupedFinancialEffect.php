<?php

namespace App\Support\Financial;

use App\Models\AccountPayable;
use App\Models\AccountPayableInstallment;
use App\Models\AccountPayableInstallmentPayment;
use App\Models\AccountReceivableInstallment;
use App\Models\AccountReceivableInstallmentPayment;
use App\Models\BankReconciliationGroupItem;
use App\Models\CashMovement;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/** One validity rule for Eloquent and SQL reports; no global scope hides history. */
final class GroupedFinancialEffect
{
    private static ?int $executingGroup = null;

    public static function effective($query, string $column, string $reference): mixed
    {
        return $query->whereNotExists(function ($sub) use ($column, $reference): void {
            $sub->selectRaw('1')->from('bank_reconciliation_group_items as effect_item')
                ->join('bank_reconciliation_groups as effect_group', 'effect_group.id', '=', 'effect_item.bank_reconciliation_group_id')
                ->whereColumn('effect_item.'.$reference, $column)
                ->where('effect_group.operation_type', 'retained_receipt')->where('effect_group.status', 'cancelled');
        });
    }

    public static function reference(Model $model): string
    {
        return match ($model::class) {
            AccountReceivableInstallmentPayment::class => 'receivable_payment_id',
            AccountPayableInstallmentPayment::class => 'payable_payment_id',
            CashMovement::class => 'cash_movement_id',
        };
    }

    public static function assertMutable(Model $model): void
    {
        $item = BankReconciliationGroupItem::query()->where(self::reference($model), $model->getKey())->first();
        if ($item && self::$executingGroup !== (int) $item->bank_reconciliation_group_id) {
            throw ValidationException::withMessages(['operation' => 'Registro vinculado a recebimento com despesas retidas. Utilize a correção ou devolução da operação completa.']);
        }
    }

    public static function within(int $groupId, Closure $callback): mixed
    {
        $previous = self::$executingGroup;
        self::$executingGroup = $groupId;
        try {
            return $callback();
        } finally {
            self::$executingGroup = $previous;
        }
    }

    public static function assertDocumentMutable(Model $model): void
    {
        $items = BankReconciliationGroupItem::query();
        if ($model instanceof AccountPayable) {
            $items->whereHas('payablePayment.installment', fn ($q) => $q->where('account_payable_id', $model->id));
        } elseif ($model instanceof AccountPayableInstallment) {
            $items->whereHas('payablePayment', fn ($q) => $q->where('account_payable_installment_id', $model->id));
        } elseif ($model instanceof AccountReceivableInstallment) {
            if (! $model->isDirty(['company_id', 'original_amount', 'financial_category_id', 'chart_account_id', 'cost_center_id', 'result_center_id'])) {
                return;
            }
            $items->whereHas('receivablePayment', fn ($q) => $q->where('account_receivable_installment_id', $model->id));
            $items->whereHas('group', fn ($q) => $q->where('status', '!=', 'cancelled'));
        } else {
            return;
        }
        if ($items->where('bank_reconciliation_group_id', '!=', self::$executingGroup ?? 0)->exists()) {
            throw ValidationException::withMessages(['operation' => 'Documento vinculado a recebimento com despesas retidas. Corrija a operação de origem.']);
        }
    }
}
