<?php

namespace App\Filament\Clusters\Financial\Resources\AccountReceivableInstallments\Tables;

use App\Enum\AccountReceivable\Status;
use App\Filament\Clusters\Financial\Actions\IgnoreFinancialAction;
use App\Filament\Clusters\Financial\Actions\RefreshChartAccountFromCategoryBulkAction;
use App\Filament\Clusters\Financial\Resources\AccountReceivables\AccountReceivableResource;
use App\Filament\Clusters\Financial\Resources\AccountReceivables\RelationManagers\Actions\DeleteInstallmentAction;
use App\Filament\Clusters\Financial\Resources\AccountReceivables\RelationManagers\Actions\EditInstallmentAction;
use App\Filament\Clusters\Financial\Resources\AccountReceivables\RelationManagers\Actions\RegisterInstallmentPaymentAction;
use App\Filament\Clusters\Financial\Resources\Components\FinancialTableColumns;
use App\Filament\Clusters\Sales\Resources\Components\SelectPartner;
use App\Models\AccountReceivableInstallment;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Malzariey\FilamentDaterangepickerFilter\Filters\DateRangeFilter;

class AccountReceivableInstallmentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('sequence_number')
            ->columns([
                TextColumn::make('accountReceivable.counterparty_label')
                    ->label('Cliente')
                    ->getStateUsing(fn (AccountReceivableInstallment $record): string => $record->accountReceivable?->counterparty_label ?? '-')
                    ->description(fn (AccountReceivableInstallment $record): ?string => $record->description)
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->where(function (Builder $query) use ($search): void {
                            $query->where('description', 'like', "%{$search}%")
                                ->orWhereHas('accountReceivable', function (Builder $accountQuery) use ($search): void {
                                    $accountQuery->whereHas('customer', fn (Builder $customerQuery) => $customerQuery->where('name', 'like', "%{$search}%"))
                                        ->orWhere('manual_counterparty_name', 'like', "%{$search}%");
                                });
                        });
                    })
                    ->limit(40)
                    ->url(fn (AccountReceivableInstallment $record): ?string => $record->accountReceivable
                        ? AccountReceivableResource::getUrl('view', ['record' => $record->accountReceivable])
                        : null),
                TextColumn::make('accountReceivable.document_number')
                    ->label('Nº Documento')
                    ->searchable()
                    ->sortable()
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('sequence_number')
                    ->label('Parcela')
                    ->badge()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('due_date')
                    ->label('Vencimento')
                    ->date('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('due_amount')
                    ->label('Valor Atual')
                    ->money('BRL')
                    ->sortable()
                    ->summarize(Sum::make('due_amount')->money('BRL', 100))
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('received_amount')
                    ->label('Valor Recebido')
                    ->money('BRL')
                    ->sortable()
                    ->summarize(Sum::make('received_amount')->money('BRL', 100))
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('balance_amount')
                    ->label('Saldo')
                    ->money('BRL')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('status')
                    ->description(fn ($record) => $record->ignored_at ? 'Ignorada no módulo' : ($record->status->value !== 'cancelled' && (float) $record->balance_amount > 0 && $record->due_date->lt(today()) ? 'Vencida' : null))
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state?->description() ?? '-')
                    ->color(fn ($state) => $state?->color() ?? 'gray')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('received_date')
                    ->label('Data Receb.')
                    ->date('d/m/Y')
                    ->placeholder('-')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('financialCategory.full_name')
                    ->label('Categoria')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label('Data de Cadastro')
                    ->date('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label('Data de Atualização')
                    ->date('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('due_date', 'asc')
            ->persistFiltersInSession()
            ->filters([
                Filter::make('customer_id')
                    ->label('Parceiro')
                    ->schema([
                        SelectPartner::make('customer_id', 'all')->required(false),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        filled($data['customer_id'] ?? null),
                        fn (Builder $query): Builder => $query->whereHas('accountReceivable',
                            fn (Builder $accountQuery): Builder => $accountQuery->where('customer_id', $data['customer_id']),
                        ),
                    )),
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(Status::toSelectArray())
                    ->multiple()
                    ->native(false),
                DateRangeFilter::make('due_date')
                    ->label('Vencimento')
                    ->autoApply()
                    ->firstDayOfWeek(0)
                    ->alwaysShowCalendar(),
            ])
            ->recordActions([
                ActionGroup::make([
                    RegisterInstallmentPaymentAction::make(),
                    EditInstallmentAction::make(),
                    DeleteInstallmentAction::make(),
                    IgnoreFinancialAction::make(),
                    IgnoreFinancialAction::make(restore: true),
                    Action::make('open_account')
                        ->label('Abrir agrupador')
                        ->icon('heroicon-o-folder-open')
                        ->url(fn (AccountReceivableInstallment $record): ?string => $record->accountReceivable
                            ? AccountReceivableResource::getUrl('view', ['record' => $record->accountReceivable])
                            : null), ])->icon(Heroicon::Bars3),
            ], RecordActionsPosition::BeforeCells)
            ->toolbarActions([
                Action::make('create_account_receivable')
                    ->label('Novo lancamento')
                    ->icon('heroicon-o-plus')
                    ->color('gray')
                    ->url(AccountReceivableResource::getUrl('create')),
                RefreshChartAccountFromCategoryBulkAction::make(),
                IgnoreFinancialAction::make(bulk: true),
                IgnoreFinancialAction::make(restore: true, bulk: true),
            ])
            ->emptyStateHeading('Nenhuma parcela encontrada')
            ->emptyStateDescription('As parcelas das contas a receber aparecerao aqui.')
            ->tap(fn (Table $table) => FinancialTableColumns::configure($table));
    }
}
