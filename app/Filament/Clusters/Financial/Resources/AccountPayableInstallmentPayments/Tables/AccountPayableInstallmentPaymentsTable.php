<?php

namespace App\Filament\Clusters\Financial\Resources\AccountPayableInstallmentPayments\Tables;

use App\Filament\Clusters\Financial\Resources\AccountPayables\AccountPayableResource;
use App\Filament\Clusters\Financial\Resources\AccountPayables\RelationManagers\Actions\DeletePaymentAction;
use App\Filament\Clusters\Financial\Resources\AccountPayables\RelationManagers\Actions\EditPaymentAction;
use App\Filament\Clusters\Sales\Resources\Components\SelectPartner;
use App\Models\AccountPayableInstallmentPayment;
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

class AccountPayableInstallmentPaymentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                TextColumn::make('installment.accountPayable.supplier.name')
                    ->label('Fornecedor')
                    ->searchable()
                    ->sortable()
                    ->limit(40),
                TextColumn::make('installment.accountPayable.document_number')
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
                    ->label('Valor Pago')
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
                Filter::make('supplier_id')
                    ->label('Fornecedor')
                    ->schema([
                        SelectPartner::make('supplier_id', 'all'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $supplierId = $data['supplier_id'] ?? null;

                        if (blank($supplierId)) {
                            return $query;
                        }

                        return $query->whereHas('installment.accountPayable', function (Builder $query) use ($supplierId): Builder {
                            return $query->where('supplier_id', $supplierId);
                        });
                    }),
                SelectFilter::make('financial_account_id')
                    ->label('Conta')
                    ->relationship('financialAccount', 'name')
                    ->searchable()
                    ->preload(),
                DateRangeFilter::make('payment_date')
                    ->label('Data do pagamento')
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
                        ->url(fn (AccountPayableInstallmentPayment $record): ?string => $record->installment?->accountPayable
                            ? AccountPayableResource::getUrl('edit', ['record' => $record->installment->accountPayable])
                            : null),
                ])->icon(Heroicon::Bars3),
            ], RecordActionsPosition::BeforeCells)
            ->emptyStateHeading('Nenhum pagamento registrado')
            ->emptyStateDescription('Os pagamentos de parcelas à pagar aparecerão aqui.')
            ->columnManagerLayout(ColumnManagerLayout::Modal)
            ->columnManagerColumns(2);
    }
}
