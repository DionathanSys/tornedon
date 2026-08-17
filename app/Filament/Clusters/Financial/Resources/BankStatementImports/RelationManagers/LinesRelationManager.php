<?php

namespace App\Filament\Clusters\Financial\Resources\BankStatementImports\RelationManagers;

use App\Filament\Clusters\Financial\Resources\BankStatementImports\RelationManagers\Actions\CreateManualMovementAction;
use App\Filament\Clusters\Financial\Resources\BankStatementImports\RelationManagers\Actions\IgnoreStatementLineAction;
use App\Filament\Clusters\Financial\Resources\BankStatementImports\RelationManagers\Actions\ReconcileMovementAction;
use App\Filament\Clusters\Financial\Resources\BankStatementImports\RelationManagers\Actions\ReconcilePayableInstallmentAction;
use App\Filament\Clusters\Financial\Resources\BankStatementImports\RelationManagers\Actions\ReconcileReceivableInstallmentAction;
use App\Filament\Clusters\Financial\Resources\BankStatementImports\RelationManagers\Actions\ReopenIgnoredStatementLineAction;
use App\Filament\Clusters\Financial\Resources\BankStatementImports\RelationManagers\Actions\ReverseStatementLineReconciliationAction;
use App\Models\BankStatementLine;
use App\Services\Financial\BankStatement\ResolveBankStatementLineService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
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
                TextColumn::make('suggestions_count')
                    ->label('Sugestões')
                    ->badge()
                    ->getStateUsing(fn (BankStatementLine $record): int => count($record->suggestions()))
                    ->color(fn (int $state): string => $state > 1 ? 'info' : ($state === 1 ? 'success' : 'gray')),
            ])
            ->defaultSort('transaction_date', 'desc')
            ->headerActions([])
            ->recordActions([
                Action::make('refresh_suggestions')
                    ->label('Atualizar sugestões')
                    ->icon('heroicon-o-arrow-path')
                    ->iconButton()
                    ->visible(fn (BankStatementLine $record): bool => $record->reconciliation_status?->canResolve() === true)
                    ->action(function (BankStatementLine $record): void {
                        $service = app(ResolveBankStatementLineService::class);
                        $service->refreshSuggestions($record);

                        Notification::make()
                            ->title('Sugestões atualizadas.')
                            ->success()
                            ->send();
                    }),
                Action::make('use_single_suggestion')
                    ->label('Usar sugestão')
                    ->icon('heroicon-o-sparkles')
                    ->color('success')
                    ->visible(fn (BankStatementLine $record): bool => $record->reconciliation_status?->canResolve() === true && count($record->suggestions()) === 1)
                    ->action(function (BankStatementLine $record): void {
                        $this->applySuggestion($record, $record->suggestions()[0]);
                    }),
                Action::make('choose_suggestion')
                    ->label('Escolher sugestão')
                    ->icon('heroicon-o-sparkles')
                    ->color('info')
                    ->iconButton()
                    ->visible(fn (BankStatementLine $record): bool => $record->reconciliation_status?->canResolve() === true && $record->suggestions() !== [])
                    ->schema(fn (Schema $schema, BankStatementLine $record): Schema => $schema->components([
                        Repeater::make('suggestions')
                            ->hiddenLabel()
                            ->default($record->suggestions())
                            ->schema([
                                TextInput::make('label')
                                    ->label('Candidato')
                                    ->disabled(),
                                TextInput::make('score')
                                    ->label('Score')
                                    ->disabled(),
                                TextInput::make('origin_type')
                                    ->label('Tipo')
                                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                                        'cash_movement' => 'Movimento financeiro',
                                        'account_payable_installment' => 'Conta a pagar',
                                        'account_receivable_installment' => 'Conta a receber',
                                        default => 'Candidato',
                                    })
                                    ->disabled(),
                                Textarea::make('reason')
                                    ->label('Motivo')
                                    ->rows(2)
                                    ->columnSpanFull()
                                    ->disabled(),
                            ])
                            ->columns(3)
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable(false)
                            ->extraItemActions([
                                Action::make('apply_suggestion')
                                    ->label('Usar esta sugestão')
                                    ->icon('heroicon-o-check')
                                    ->color('success')
                                    ->action(function (array $arguments, Repeater $component) use ($record): void {
                                        $suggestion = $component->getRawState()[$arguments['item']] ?? null;

                                        if (! is_array($suggestion)) {
                                            return;
                                        }

                                        $this->applySuggestion($record, $suggestion);
                                    }),
                            ]),
                    ]))
                    ->modalHeading('Escolher sugestão')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Fechar'),
                ReconcileMovementAction::make()->iconButton(),
                ReconcilePayableInstallmentAction::make()->iconButton(),
                ReconcileReceivableInstallmentAction::make()->iconButton(),
                CreateManualMovementAction::make()->iconButton(),
                IgnoreStatementLineAction::make()->iconButton(),
                ReopenIgnoredStatementLineAction::make()->iconButton(),
                ReverseStatementLineReconciliationAction::make()->iconButton(),
            ]);
    }

    /**
     * @param  array<string, mixed>  $suggestion
     */
    private function applySuggestion(BankStatementLine $line, array $suggestion): void
    {
        $service = app(ResolveBankStatementLineService::class);
        $resolved = match ($suggestion['origin_type'] ?? null) {
            'cash_movement' => $service->reconcileWithCashMovement($line, (int) $suggestion['origin_id'], auth()->id()),
            'account_payable_installment' => $service->reconcileWithPayableInstallment($line, (int) $suggestion['origin_id'], [
                'payment_date' => $line->transaction_date?->toDateString(),
                'notes' => $line->description,
            ], auth()->id()),
            'account_receivable_installment' => $service->reconcileWithReceivableInstallment($line, (int) $suggestion['origin_id'], [
                'payment_date' => $line->transaction_date?->toDateString(),
                'notes' => $line->description,
            ], auth()->id()),
            default => null,
        };

        Notification::make()
            ->title($resolved ? ($service->getMessageUser() ?: 'Sugestão conciliada com sucesso.') : ($service->getMessageUser() ?: 'Não foi possível conciliar a sugestão.'))
            ->{$resolved ? 'success' : 'danger'}()
            ->send();
    }
}
