<?php

namespace App\Filament\Clusters\Financial\Resources\BankStatementImports\Tables;

use App\Enum\Financial\BankStatementLineStatus;
use App\Models\BankStatementImport;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class BankStatementImportsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount([
                'lines',
                'lines as pending_lines_count' => fn (Builder $lines) => $lines->whereIn('reconciliation_status', [
                    BankStatementLineStatus::PENDING,
                    BankStatementLineStatus::NEEDS_REVIEW,
                    BankStatementLineStatus::REVERSED,
                ]),
                'lines as ignored_lines_count' => fn (Builder $lines) => $lines->where('reconciliation_status', BankStatementLineStatus::IGNORED),
            ]))
            ->columns([
                TextColumn::make('imported_at')
                    ->label('Importado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('financialAccount.name')
                    ->label('Conta')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('metadata.institution_name')
                    ->label('Banco')
                    ->placeholder('-')
                    ->sortable(),
                TextColumn::make('file_name')
                    ->label('Arquivo')
                    ->searchable()
                    ->limit(40),
                TextColumn::make('line_count')
                    ->label('Linhas')
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Status da importação')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state?->description() ?? (string) $state),
                TextColumn::make('reconciliation_summary')
                    ->label('Conciliação')
                    ->state(function (BankStatementImport $record): string {
                        if ((int) $record->lines_count === 0) {
                            return 'Sem registros';
                        }

                        if ((int) $record->pending_lines_count > 0) {
                            $label = (int) $record->pending_lines_count === 1 ? 'pendente' : 'pendentes';

                            return "{$record->pending_lines_count} {$label} de {$record->lines_count}";
                        }

                        if ((int) $record->ignored_lines_count > 0) {
                            $label = (int) $record->ignored_lines_count === 1 ? 'ignorado' : 'ignorados';

                            return "Concluído · {$record->ignored_lines_count} {$label}";
                        }

                        return '100% conciliado';
                    })
                    ->badge()
                    ->color(fn (BankStatementImport $record): string => match (true) {
                        (int) $record->lines_count === 0 => 'gray',
                        (int) $record->pending_lines_count > 0 => 'warning',
                        default => 'success',
                    }),
            ])
            ->recordActions([
                ViewAction::make()->iconButton(),
            ])
            ->defaultSort('imported_at', 'desc');
    }
}
