<?php

namespace App\Filament\Clusters\Financial\Resources\CashMovements\Tables;

use App\Enum\Financial\CashMovementDirection;
use App\Filament\Clusters\Financial\Actions\RefreshChartAccountFromCategoryBulkAction;
use App\Filament\Clusters\Financial\Resources\CashMovements\Actions\CreateTransferAction;
use App\Filament\Clusters\Financial\Resources\CashMovements\Actions\EditTransferAction;
use App\Filament\Clusters\Financial\Resources\CashMovements\Actions\ReverseTransferAction;
use App\Models\CashMovement;
use App\Models\FinancialCategory;
use App\Services\Financial\CashMovementService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Malzariey\FilamentDaterangepickerFilter\Filters\DateRangeFilter;

class CashMovementsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['financialAccount', 'financialCategory', 'counterpartyPartner', 'counterpartyFinancialAccount', 'company',
                    'transferGroupRecords' => fn ($query) => $query->where('company_id', Filament::getTenant()->id)->withExists(['statementLines', 'statementAllocations']),
                    'payablePayment.installment.accountPayable.supplier', 'receivablePayment.installment.accountReceivable.customer'])
                // Filament rebuilds the table query for each action context. Compile these
                // scoped subqueries only when the records are actually fetched.
                ->withGlobalScope('statement_flags', fn (Builder $query) => $query->withExists(['statementLines', 'statementAllocations'])))
            ->columns([
                TextColumn::make('transaction_date')
                    ->label('Data')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('financialAccount.name')
                    ->label('Conta')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('financialCategory.full_name')
                    ->label('Categoria')
                    ->state(fn (CashMovement $record): ?string => self::categoryLabel($record))
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereHas('financialCategory', fn (Builder $query) => $query->where('name', 'like', "%{$search}%")))
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('counterparty_label')
                    ->label('Parceiro')
                    ->getStateUsing(fn (CashMovement $record): string => $record->counterparty_label)
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->where(function (Builder $query) use ($search): void {
                            $query->whereHas('counterpartyPartner', fn (Builder $partnerQuery) => $partnerQuery->where('name', 'like', "%{$search}%"))
                                ->orWhere('manual_counterparty_name', 'like', "%{$search}%");
                        });
                    })
                    ->limit(40)
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('direction')
                    ->label('Tipo')
                    ->formatStateUsing(fn ($state) => $state?->description() ?? '-')
                    ->badge()
                    ->color(fn ($state) => $state?->color() ?? 'gray')
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('amount')
                    ->label('Valor')
                    ->money('BRL')
                    ->sortable()
                    ->color(fn ($state, $record): string => $record->direction === CashMovementDirection::OUTFLOW ? 'danger' : 'info'),
                TextColumn::make('description')
                    ->label('Descrição')
                    ->searchable()
                    ->limit(50)
                    ->tooltip(fn ($state) => $state)
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('origin_label')
                    ->label('Origem')
                    ->toggleable(),
                TextColumn::make('transfer_group_id')
                    ->label('Vinculo')
                    ->formatStateUsing(fn ($state, $record): string => $record->isTransfer() ? 'Transferencia' : '-')
                    ->badge()
                    ->color(fn ($state, $record): string => $record->isTransfer() ? 'info' : 'gray')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('tracking_label')
                    ->label('De / Para')
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('statement_lines_exists')
                    ->label('Conciliado')
                    ->boolean()
                    ->state(fn ($record): bool => $record->hasStatementLinks()),
                TextColumn::make('reversed_at')
                    ->label('Estornado em')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label('Criado em')
                    ->dateTime('d/m/Y H:i')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label('Atualizado em')
                    ->dateTime('d/m/Y H:i')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('direction')
                    ->label('Direção')
                    ->options(CashMovementDirection::toSelectArray())
                    ->native(false),
                SelectFilter::make('financial_account_id')
                    ->label('Conta')
                    ->relationship('financialAccount', 'name', fn (Builder $query) => $query->where('company_id', Filament::getTenant()->id))
                    ->searchable()
                    ->preload(),
                SelectFilter::make('financial_category_id')
                    ->label('Categoria')
                    ->options(fn (): array => FinancialCategory::optionsForCompany(Filament::getTenant()->id, 'cash_movement'))
                    ->searchable()
                    ->native(false),
                TernaryFilter::make('conciliado')
                    ->label('Conciliado')
                    ->queries(
                        true: fn ($query) => $query->reconciled(),
                        false: fn ($query) => $query->unreconciled(),
                        blank: fn ($query) => $query,
                    )
                    ->native(false),
                DateRangeFilter::make('transaction_date')
                    ->label('Data Movimento')
                    ->icon('heroicon-o-backspace')
                    ->autoApply()
                    ->firstDayOfWeek(0)
                    ->alwaysShowCalendar()
                    ->defaultThisMonth(),
            ])
            ->groups([
                Group::make('transaction_date')
                    ->label('Data')
                    ->date('d/m/Y'),
                Group::make('financialAccount.name')
                    ->label('Conta'),
                Group::make('financialCategory.name')
                    ->label('Categoria'),
            ])
            ->recordUrl(null)
            ->recordActions([
                EditAction::make()
                    ->iconButton()
                    ->visible(fn ($record): bool => $record->origin_type === 'manual' && ! $record->isTransfer()),
                EditTransferAction::make()
                    ->iconButton(),
                ReverseTransferAction::make()
                    ->iconButton(),
                Action::make('delete_safely')
                    ->label('Excluir')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->iconButton()
                    ->requiresConfirmation()
                    ->modalHeading('Excluir movimento financeiro')
                    ->modalDescription('Se este movimento veio de uma baixa de parcela, a baixa será desfeita e a conta voltará ao estado anterior. Movimentos conciliados serão preservados via estorno quando aplicável.')
                    ->action(function (CashMovement $record): void {
                        $service = app(CashMovementService::class);

                        if (! $service->deleteSafely($record, Auth::id())) {
                            Notification::make()
                                ->title($service->getMessageUser() ?: 'Não foi possível excluir o movimento.')
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title($service->getMessage() ?: 'Movimento financeiro excluído com sucesso.')
                            ->success()
                            ->send();
                    }),
            ], RecordActionsPosition::BeforeCells)
            ->toolbarActions([
                CreateAction::make()
                    ->label('Mov. Manual'),
                CreateTransferAction::make(),
                RefreshChartAccountFromCategoryBulkAction::make(),
            ])
            ->defaultSort('transaction_date', 'desc')
            ->emptyStateHeading('Nenhum movimento financeiro encontrado');
    }

    private static function categoryLabel(CashMovement $record): ?string
    {
        // Labels are presentation-only and shared by all rows in this request.
        $key = 'cash_movement_category_labels.'.Filament::getTenant()->id.'.'.Auth::id();
        $labels = request()->attributes->get($key);
        if ($labels === null) {
            $labels = FinancialCategory::hierarchyOptionsForCompany(Filament::getTenant()->id);
            request()->attributes->set($key, $labels);
        }

        return $labels[$record->financial_category_id] ?? $record->financialCategory?->name;
    }
}
