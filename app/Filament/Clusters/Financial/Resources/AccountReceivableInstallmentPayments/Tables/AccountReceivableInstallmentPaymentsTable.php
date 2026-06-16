<?php

namespace App\Filament\Clusters\Financial\Resources\AccountReceivableInstallmentPayments\Tables;

use App\Filament\Clusters\Financial\Resources\AccountReceivables\AccountReceivableResource;
use App\Filament\Clusters\Financial\Resources\AccountReceivables\RelationManagers\Actions\DeletePaymentAction;
use App\Filament\Clusters\Financial\Resources\AccountReceivables\RelationManagers\Actions\EditPaymentAction;
use App\Filament\Clusters\Sales\Resources\Components\SelectPartner;
use App\Models\AccountReceivableInstallmentPayment;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\ColumnManagerLayout;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Malzariey\FilamentDaterangepickerFilter\Filters\DateRangeFilter;

class AccountReceivableInstallmentPaymentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                TextColumn::make('installment.accountReceivable.customer.name')
                    ->label('Cliente')
                    ->searchable()
                    ->sortable()
                    ->limit(40),
                TextColumn::make('installment.accountReceivable.document_number')
                    ->label('Nº Documento')
                    ->searchable()
                    ->sortable()
                    ->placeholder('-'),
                TextColumn::make('installment.sequence_number')
                    ->label('Parcela')
                    ->badge()
                    ->sortable(),
                TextColumn::make('payment_date')
                    ->label('Data')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('amount')
                    ->label('Valor Recebido')
                    ->money('BRL')
                    ->sortable(),
                TextColumn::make('interest_amount')
                    ->label('Juros')
                    ->money('BRL')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('fine_amount')
                    ->label('Multa')
                    ->money('BRL')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('discount_amount')
                    ->label('Desconto')
                    ->money('BRL')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('financialAccount.name')
                    ->label('Conta')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('description')
                    ->label('Descrição')
                    ->searchable()
                    ->limit(50)
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('notes')
                    ->label('Observações')
                    ->limit(40)
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: false),
            ])
            ->defaultSort('payment_date', 'desc')
            ->persistFiltersInSession()
            ->filters([
                Filter::make('customer_id')
                    ->label('Cliente')
                    ->schema([
                        SelectPartner::make('customer_id', 'all'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $customerId = $data['customer_id'] ?? null;

                        if (blank($customerId)) {
                            return $query;
                        }

                        return $query->whereHas('installment.accountReceivable', function (Builder $query) use ($customerId): Builder {
                            return $query->where('customer_id', $customerId);
                        });
                    }),
                SelectFilter::make('financial_account_id')
                    ->label('Conta')
                    ->relationship('financialAccount', 'name')
                    ->searchable()
                    ->preload(),
                DateRangeFilter::make('payment_date')
                    ->label('Data do recebimento')
                    ->autoApply()
                    ->firstDayOfWeek(0)
                    ->alwaysShowCalendar(),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditPaymentAction::make(),
                    DeletePaymentAction::make(),
                    Action::make('open_account')
                        ->label('Abrir agrupador')
                        ->icon('heroicon-o-folder-open')
                        ->url(fn (AccountReceivableInstallmentPayment $record): ?string => $record->installment?->accountReceivable
                            ? AccountReceivableResource::getUrl('edit', ['record' => $record->installment->accountReceivable])
                            : null),
                ])->icon(Heroicon::Bars3),
            ], RecordActionsPosition::BeforeCells)
            ->emptyStateHeading('Nenhum recebimento registrado')
            ->emptyStateDescription('Os recebimentos de parcelas a receber aparecerao aqui.')
            ->columnManagerLayout(ColumnManagerLayout::Modal)
            ->columnManagerColumns(2);
    }
}
