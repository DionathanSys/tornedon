<?php

namespace App\Filament\Clusters\Financial\Resources\BankStatementImports\RelationManagers\Actions;

use App\Models\AccountReceivableInstallment;
use App\Models\BankStatementLine;
use App\Services\Financial\BankStatement\ResolveBankStatementLineService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Leandrocfe\FilamentPtbrFormFields\Money;
use Livewire\Component;

final class ReconcileReceivableInstallmentAction
{
    public static function make(): Action
    {
        return Action::make('reconcile_receivable_installment')
            ->label('Baixar conta a receber')
            ->icon('heroicon-o-arrow-up-circle')
            ->color('success')
            ->visible(fn (BankStatementLine $record): bool => $record->isInflow() && $record->reconciliation_status?->value !== 'reconciled')
            ->schema(fn (Schema $schema) => $schema
                ->columns(2)
                ->components([
                    Select::make('installment_id')
                        ->label('Parcela')
                        ->options(fn (BankStatementLine $record): array => self::optionsForLine($record))
                        ->searchable()
                        ->preload()
                        ->native(false)
                        ->required()
                        ->columnSpanFull(),
                    DatePicker::make('payment_date')
                        ->label('Data do recebimento')
                        ->default(fn (BankStatementLine $record) => $record->transaction_date)
                        ->required(),
                    Money::make('interest_amount')
                        ->label('Juros')
                        ->default(0),
                    Money::make('fine_amount')
                        ->label('Multa')
                        ->default(0),
                    Money::make('discount_amount')
                        ->label('Desconto')
                        ->default(0),
                    Textarea::make('notes')
                        ->label('Observações')
                        ->rows(3)
                        ->columnSpanFull(),
                ]))
            ->action(function (BankStatementLine $record, array $data): void {
                $service = app(ResolveBankStatementLineService::class);
                $resolved = $service->reconcileWithReceivableInstallment($record, (int) $data['installment_id'], $data, auth()->id());

                if ($service->hasError() || $resolved === null) {
                    Notification::make()
                        ->title($service->getMessageUser() ?: 'Erro ao baixar parcela a receber.')
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title($service->getMessage() ?: 'Parcela baixada e linha conciliada com sucesso.')
                    ->success()
                    ->send();
            })
            ->after(function (Component $livewire): void {
                $livewire->dispatch('refresh-statement-lines');
            });
    }

    private static function optionsForLine(BankStatementLine $line): array
    {
        $suggestions = collect($line->suggestions())
            ->where('origin_type', 'account_receivable_installment')
            ->mapWithKeys(fn (array $suggestion) => [
                (int) $suggestion['origin_id'] => "{$suggestion['label']} [score {$suggestion['score']}]",
            ]);

        $openInstallments = AccountReceivableInstallment::query()
            ->with('accountReceivable.customer')
            ->where('company_id', $line->company_id)
            ->where('balance_amount', '>', 0)
            ->orderBy('due_date')
            ->limit(30)
            ->get()
            ->mapWithKeys(fn (AccountReceivableInstallment $installment) => [
                $installment->id => sprintf(
                    'AR %s | %s | %s | R$ %s',
                    $installment->sequence_number,
                    $installment->accountReceivable?->customer?->name ?? 'Sem cliente',
                    $installment->due_date?->format('d/m/Y'),
                    number_format((float) $installment->balance_amount, 2, ',', '.')
                ),
            ]);

        return $suggestions->union($openInstallments)->toArray();
    }
}
