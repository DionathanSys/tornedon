<?php

namespace App\Filament\Clusters\Financial\Resources\BankStatementImports\Tables;

use App\Models\AccountReceivableInstallment;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class StatementLineReceivableInstallmentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => AccountReceivableInstallment::query()
                ->where('company_id', Filament::getTenant()->id)
                ->where('balance_amount', '>', 0))
            ->recordTitleAttribute('description')
            ->columns([
                TextColumn::make('accountReceivable.counterparty_label')
                    ->label('Cliente')
                    ->getStateUsing(fn (AccountReceivableInstallment $record): string => $record->accountReceivable?->counterparty_label ?? '-')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas('accountReceivable', fn (Builder $accountQuery) => $accountQuery
                        ->whereHas('customer', fn (Builder $customerQuery) => $customerQuery->where('name', 'like', "%{$search}%"))
                        ->orWhere('manual_counterparty_name', 'like', "%{$search}%"))),
                TextColumn::make('description')
                    ->label('Descrição')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('sequence_number')
                    ->label('Parcela')
                    ->sortable(),
                TextColumn::make('due_date')
                    ->label('Vencimento')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('balance_amount')
                    ->label('Saldo')
                    ->money('BRL')
                    ->sortable(),
            ])
            ->defaultSort('due_date');
    }
}
