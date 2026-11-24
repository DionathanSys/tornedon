<?php

namespace App\Filament\Clusters\Financial\Resources\BankStatementImports\RelationManagers\Actions;

use App\Models\BankStatementLine;
use App\Services\Financial\BankStatement\ReceiptWithRetainedExpensesReversalService;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;

final class ReturnRetainedReceiptAction
{
    public static function make(): Action
    {
        return Action::make('return_retained_receipt')
            ->label('Registrar devolução efetiva')->icon('heroicon-o-arrow-uturn-left')->color('warning')
            ->visible(fn (BankStatementLine $record) => $record->reconciliationGroup?->operation_type === 'retained_receipt' && $record->reconciliationGroup?->status === 'confirmed')
            ->modalDescription('Registre uma devolução já ocorrida. Esta ação não envia dinheiro. Os recebimentos da operação serão devolvidos integralmente; selecione apenas as taxas efetivamente ressarcidas pelo intermediador.')
            ->schema([
                DatePicker::make('return_date')->label('Data efetiva da devolução')->required(),
                CheckboxList::make('refunded_expense_ids')->label('Despesas efetivamente ressarcidas')->default([])
                    ->options(fn (BankStatementLine $record) => $record->reconciliationGroup->items()->where('role', 'retained_expense')->with('payablePayment.installment.accountPayable')->get()->mapWithKeys(fn ($item) => [$item->id => '#'.$item->payablePayment->installment->account_payable_id.' — '.$item->payablePayment->installment->accountPayable->counterparty_label.' — R$ '.number_format($item->payablePayment->amount, 2, ',', '.')])->all()),
                Textarea::make('reason')->label('Motivo / referência da devolução')->required()->minLength(5)->maxLength(2000),
            ])
            ->action(function (BankStatementLine $record, array $data, Action $action): void {
                try {
                    app(ReceiptWithRetainedExpensesReversalService::class)->returnFunds($record->company_id, $record->bank_reconciliation_group_id, auth()->id(), $data['return_date'], $data['reason'], array_map('intval', $data['refunded_expense_ids'] ?? []));
                } catch (ValidationException $exception) {
                    Notification::make()->danger()->title('Não foi possível registrar a devolução')->body(collect($exception->errors())->flatten()->implode(' '))->send();
                    $action->halt();
                }
                Notification::make()->success()->title('Devolução registrada na data informada')->send();
            });
    }
}
