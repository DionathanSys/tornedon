<?php

namespace App\Filament\Clusters\Financial\Resources\BankStatementImports\RelationManagers;

use App\Filament\Clusters\Financial\Resources\BankStatementImports\RelationManagers\Actions\CreateManualMovementAction;
use App\Filament\Clusters\Financial\Resources\BankStatementImports\RelationManagers\Actions\IgnoreStatementLineAction;
use App\Filament\Clusters\Financial\Resources\BankStatementImports\RelationManagers\Actions\ReconcileMovementAction;
use App\Filament\Clusters\Financial\Resources\BankStatementImports\RelationManagers\Actions\ReconcilePayableInstallmentAction;
use App\Filament\Clusters\Financial\Resources\BankStatementImports\RelationManagers\Actions\ReconcileReceivableInstallmentAction;
use App\Models\BankStatementLine;
use App\Services\Financial\BankStatement\ResolveBankStatementLineService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Livewire\Attributes\On;

class LinesRelationManager extends RelationManager
{
    protected static string $relationship = 'lines';

    protected static ?string $title = 'Linhas do Extrato';

    protected static ?string $modelLabel = 'Linha do extrato';

    protected static ?string $pluralModelLabel = 'Linhas do extrato';

    protected static string|BackedEnum|null $icon = Heroicon::Bars3BottomLeft;

    #[On('refresh-statement-lines')]
    public function refreshStatementLines(): void
    {
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('transaction_date')
                    ->label('Data')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('description')
                    ->label('Descrição')
                    ->searchable()
                    ->wrap()
                    ->limit(60),
                TextColumn::make('amount')
                    ->label('Valor')
                    ->money('BRL')
                    ->sortable(),
                TextColumn::make('metadata.direction')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state === 'outflow' ? 'Saida' : 'Entrada')
                    ->color(fn ($state) => $state === 'outflow' ? 'danger' : 'success'),
                TextColumn::make('reconciliation_status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state?->description() ?? '-')
                    ->color(fn ($state) => match ($state?->value) {
                        'reconciled' => 'success',
                        'ignored' => 'gray',
                        default => 'warning',
                    }),
                TextColumn::make('cashMovement.description')
                    ->label('Movimento vinculado')
                    ->placeholder('-')
                    ->limit(40),
                TextColumn::make('metadata.suggestions.0.label')
                    ->label('Melhor sugestão')
                    ->wrap()
                    ->placeholder('-')
                    ->tooltip(fn (BankStatementLine $record): ?string => filled(data_get($record->metadata, 'suggestions.0.label'))
                        ? data_get($record->metadata, 'suggestions.0.reason')
                        : null),
            ])
            ->defaultSort('transaction_date', 'desc')
            ->headerActions([])
            ->recordActions([
                Action::make('refresh_suggestions')
                    ->label('Atualizar sugestões')
                    ->icon('heroicon-o-arrow-path')
                    ->iconButton()
                    ->action(function (BankStatementLine $record): void {
                        $service = app(ResolveBankStatementLineService::class);
                        $service->refreshSuggestions($record);

                        Notification::make()
                            ->title('Sugestões atualizadas.')
                            ->success()
                            ->send();
                    }),
                ReconcileMovementAction::make()->iconButton(),
                ReconcilePayableInstallmentAction::make()->iconButton(),
                ReconcileReceivableInstallmentAction::make()->iconButton(),
                CreateManualMovementAction::make()->iconButton(),
                IgnoreStatementLineAction::make()->iconButton(),
            ]);
    }
}
