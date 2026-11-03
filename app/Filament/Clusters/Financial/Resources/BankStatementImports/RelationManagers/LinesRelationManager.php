<?php

namespace App\Filament\Clusters\Financial\Resources\BankStatementImports\RelationManagers;

use App\Filament\Clusters\Financial\Resources\BankStatementImports\RelationManagers\Actions\CreateManualMovementAction;
use App\Filament\Clusters\Financial\Resources\BankStatementImports\RelationManagers\Actions\IgnoreStatementLineAction;
use App\Filament\Clusters\Financial\Resources\BankStatementImports\RelationManagers\Actions\ReconcileMultipleAction;
use App\Filament\Clusters\Financial\Resources\BankStatementImports\RelationManagers\Actions\RefreshStatementSuggestionsAction;
use App\Filament\Clusters\Financial\Resources\BankStatementImports\RelationManagers\Actions\ReopenIgnoredStatementLineAction;
use App\Filament\Clusters\Financial\Resources\BankStatementImports\RelationManagers\Actions\ReverseStatementLineReconciliationAction;
use App\Filament\Clusters\Financial\Resources\BankStatementImports\RelationManagers\Actions\ViewStatementLineAction;
use App\Filament\Clusters\Financial\Resources\BankStatementLines\BankStatementSuggestionColumns;
use App\Models\BankStatementLine;
use App\Models\CashMovement;
use App\Services\Financial\BankStatement\BankStatementMovementEligibilityService;
use App\Services\Financial\BankStatement\ResolveBankStatementLineService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
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
            ->modifyQueryUsing(fn (Builder $query) => $query->where('company_id', Filament::getTenant()->id))
            ->columns([
                TextColumn::make('transaction_date')
                    ->label('Data')
                    ->date('d/m/Y')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('description')
                    ->label('Descrição')
                    ->searchable()
                    ->wrap()
                    ->limit(60)
                    ->description(fn (BankStatementLine $record): ?string => filled($name = data_get($record->metadata, 'raw.name')) ? 'Parte: '.$name : null)
                    ->color('primary')
                    ->tooltip('Clique para ver os detalhes gravados do lançamento')
                    ->action(ViewStatementLineAction::make())
                    ->toggleable(),
                TextColumn::make('amount')
                    ->label('Valor')
                    ->money('BRL')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('metadata.direction')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state === 'outflow' ? 'Saida' : 'Entrada')
                    ->color(fn ($state) => $state === 'outflow' ? 'danger' : 'success')
                    ->toggleable(),
                TextColumn::make('reconciliation_status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state?->description() ?? '-')
                    ->color(fn ($state) => match ($state?->value) {
                        'reconciled' => 'success',
                        'ignored' => 'gray',
                        default => 'warning',
                    })
                    ->toggleable(),
                TextColumn::make('linked_movements')
                    ->label('Movimentos vinculados')
                    ->getStateUsing(fn (BankStatementLine $record) => $record->bank_reconciliation_group_id ? $record->cashMovements->pluck('description')->implode('; ') : $record->cashMovement?->description)
                    ->placeholder('-')
                    ->tooltip(fn (BankStatementLine $record): ?string => filled($record->cashMovement?->description) ? $record->cashMovement?->description : null)
                    ->limit(40)
                    ->toggleable(),
                ...BankStatementSuggestionColumns::make(),
            ])
            ->defaultSort('transaction_date', 'desc')
            ->headerActions([])
            ->toolbarActions([ReconcileMultipleAction::make(bulk: true)])
            ->recordActions([
                ReconcileMultipleAction::make()->iconButton(),
                RefreshStatementSuggestionsAction::make(),
                Action::make('use_single_suggestion')
                    ->label('Usar sugestão')
                    ->icon('heroicon-o-sparkles')
                    ->color('success')
                    ->iconButton()
                    ->visible(fn (BankStatementLine $record): bool => $record->reconciliation_status?->canResolve() === true && $record->suggestionsStatus() === 'ready')
                    ->modalHidden(fn (BankStatementLine $record): bool => ! $this->suggestionRequiresExceptionReason($record, $record->suggestions()[0]))
                    ->schema(fn (Schema $schema): Schema => $schema->components([
                        Textarea::make('exception_reason')
                            ->label('Justificativa de exceção')
                            ->helperText('A sugestão está fora da margem de valor ou data configurada.')
                            ->required()
                            ->rows(3),
                    ]))
                    ->action(function (BankStatementLine $record, array $data): void {
                        $this->applySuggestion($record, $record->suggestions()[0], $data['exception_reason'] ?? null);
                    }),
                Action::make('choose_suggestion')
                    ->label('Escolher sugestão')
                    ->icon('heroicon-o-sparkles')
                    ->color('info')
                    ->iconButton()
                    ->visible(fn (BankStatementLine $record): bool => $record->reconciliation_status?->canResolve() === true && $record->suggestionsStatus() === 'ready' && count($record->suggestions()) > 1)
                    ->schema(fn (Schema $schema, BankStatementLine $record): Schema => $schema->components([
                        Radio::make('suggestion_index')
                            ->label('Sugestão')
                            ->options($this->suggestionOptions($record))
                            ->descriptions($this->suggestionDescriptions($record))
                            ->live()
                            ->required(),
                        Textarea::make('exception_reason')
                            ->label('Justificativa de exceção')
                            ->helperText('Informe-a se a sugestão selecionada estiver fora da margem de valor ou data.')
                            ->required(function (Get $get) use ($record): bool {
                                $index = $get('suggestion_index');

                                return is_numeric($index)
                                    && $this->suggestionRequiresExceptionReason(
                                        $record,
                                        $record->suggestions()[(int) $index] ?? [],
                                    );
                            })
                            ->rows(3),
                    ]))
                    ->modalHeading('Escolher sugestão')
                    ->modalSubmitActionLabel('Conciliar sugestão')
                    ->action(function (BankStatementLine $record, array $data): void {
                        $suggestion = $record->suggestions()[(int) $data['suggestion_index']] ?? null;

                        if (! is_array($suggestion)) {
                            return;
                        }

                        $this->applySuggestion($record, $suggestion, $data['exception_reason'] ?? null);
                    }),
                CreateManualMovementAction::make()->iconButton(),
                IgnoreStatementLineAction::make()->iconButton(),
                ReopenIgnoredStatementLineAction::make()->iconButton(),
                ReverseStatementLineReconciliationAction::make()->iconButton(),
            ]);
    }

    /**
     * @param  array<string, mixed>  $suggestion
     */
    private function applySuggestion(BankStatementLine $line, array $suggestion, ?string $exceptionReason = null): void
    {
        $service = app(ResolveBankStatementLineService::class);
        $resolved = match ($suggestion['origin_type'] ?? null) {
            'cash_movement' => $service->reconcileWithCashMovement($line, (int) $suggestion['origin_id'], Auth::id(), [
                'exception_reason' => $exceptionReason,
            ]),
            'account_payable_installment' => $service->reconcileWithPayableInstallment($line, (int) $suggestion['origin_id'], [
                'payment_date' => $line->transaction_date?->toDateString(),
                'notes' => $line->description,
            ], Auth::id()),
            'account_receivable_installment' => $service->reconcileWithReceivableInstallment($line, (int) $suggestion['origin_id'], [
                'payment_date' => $line->transaction_date?->toDateString(),
                'notes' => $line->description,
            ], Auth::id()),
            default => null,
        };

        Notification::make()
            ->title($resolved ? ($service->getMessageUser() ?: 'Sugestão conciliada com sucesso.') : ($service->getMessageUser() ?: 'Não foi possível conciliar a sugestão.'))
            ->{$resolved ? 'success' : 'danger'}()
            ->send();
    }

    /**
     * @param  array<string, mixed>  $suggestion
     */
    private function suggestionRequiresExceptionReason(BankStatementLine $line, array $suggestion): bool
    {
        if (($suggestion['origin_type'] ?? null) !== 'cash_movement') {
            return false;
        }

        $movement = CashMovement::query()->find($suggestion['origin_id'] ?? null);

        return $movement !== null
            && app(BankStatementMovementEligibilityService::class)->exceptionsFor($line, $movement) !== [];
    }

    /**
     * @return array<int, string>
     */
    private function suggestionOptions(BankStatementLine $line): array
    {
        return collect($line->suggestions())
            ->mapWithKeys(fn (array $suggestion, int $index): array => [
                $index => sprintf('%s (score: %s)', $suggestion['label'] ?? 'Candidato', $suggestion['score'] ?? '-'),
            ])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function suggestionDescriptions(BankStatementLine $line): array
    {
        return collect($line->suggestions())
            ->mapWithKeys(fn (array $suggestion, int $index): array => [
                $index => $suggestion['reason'] ?? '',
            ])
            ->all();
    }
}
