<?php

namespace App\Filament\Clusters\Financial\Resources\BankStatementImports\Tables;

use App\Models\BankStatementLine;
use App\Models\CashMovement;
use App\Services\Financial\BankStatement\BankStatementMovementEligibilityService;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class StatementLineCashMovementsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->query(function (Table $table): Builder {
                $line = BankStatementLine::query()
                    ->where('company_id', Filament::getTenant()->id)
                    ->find($table->getArguments()['bank_statement_line_id'] ?? null);

                if (! $line) {
                    return CashMovement::query()->whereRaw('1 = 0');
                }

                return app(BankStatementMovementEligibilityService::class)->queryForLine($line);
            })
            ->recordTitleAttribute('description')
            ->columns([
                TextColumn::make('transaction_date')
                    ->label('Data')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('description')
                    ->label('Descrição')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('counterparty_label')
                    ->label('Parceiro')
                    ->getStateUsing(fn (CashMovement $record): string => $record->counterparty_label)
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(function (Builder $query) use ($search): void {
                        $query->whereHas('counterpartyPartner', fn (Builder $partnerQuery) => $partnerQuery->where('name', 'like', "%{$search}%"))
                            ->orWhere('manual_counterparty_name', 'like', "%{$search}%");
                    })),
                TextColumn::make('amount')
                    ->label('Valor')
                    ->money('BRL')
                    ->sortable(),
            ])
            ->defaultSort('transaction_date', 'desc');
    }
}
