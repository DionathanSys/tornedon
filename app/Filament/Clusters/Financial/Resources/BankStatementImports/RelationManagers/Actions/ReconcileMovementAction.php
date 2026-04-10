<?php

namespace App\Filament\Clusters\Financial\Resources\BankStatementImports\RelationManagers\Actions;

use App\Models\BankStatementLine;
use App\Models\CashMovement;
use App\Services\Financial\BankStatement\ResolveBankStatementLineService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Livewire\Component;

final class ReconcileMovementAction
{
    public static function make(): Action
    {
        return Action::make('reconcile_movement')
            ->label('Vincular movimento')
            ->icon('heroicon-o-link')
            ->color('info')
            ->schema(fn (Schema $schema) => $schema->components([
                Select::make('cash_movement_id')
                    ->label('Movimento financeiro')
                    ->options(fn (BankStatementLine $record): array => self::optionsForLine($record))
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->required(),
            ]))
            ->visible(fn (BankStatementLine $record): bool => $record->reconciliation_status?->value !== 'reconciled')
            ->action(function (BankStatementLine $record, array $data): void {
                $service = app(ResolveBankStatementLineService::class);
                $resolved = $service->reconcileWithCashMovement($record, (int) $data['cash_movement_id'], auth()->id());

                if ($service->hasError() || $resolved === null) {
                    Notification::make()
                        ->title($service->getMessageUser() ?: 'Erro ao conciliar com movimento.')
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title($service->getMessage() ?: 'Linha conciliada com sucesso.')
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
            ->where('origin_type', 'cash_movement')
            ->mapWithKeys(fn (array $suggestion) => [
                (int) $suggestion['origin_id'] => "{$suggestion['label']} [score {$suggestion['score']}]",
            ]);

        $nearbyMovements = CashMovement::query()
            ->where('company_id', $line->company_id)
            ->where('financial_account_id', $line->financial_account_id)
            ->whereDate('transaction_date', '>=', $line->transaction_date?->copy()->subDays(10)->toDateString())
            ->whereDate('transaction_date', '<=', $line->transaction_date?->copy()->addDays(10)->toDateString())
            ->whereDoesntHave('statementLines', fn ($query) => $query->where('id', '!=', $line->id))
            ->orderByDesc('transaction_date')
            ->limit(20)
            ->get()
            ->mapWithKeys(fn (CashMovement $movement) => [
                $movement->id => sprintf(
                    '%s | %s | R$ %s',
                    $movement->transaction_date?->format('d/m/Y'),
                    $movement->description,
                    number_format((float) $movement->amount, 2, ',', '.')
                ),
            ]);

        return $suggestions->union($nearbyMovements)->toArray();
    }
}
