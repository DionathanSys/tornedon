<?php

namespace App\Filament\Clusters\Financial\Resources\AccountPayableInstallments\Tables;

use App\Enum\AccountPayable\Status;
use App\Filament\Clusters\Financial\Resources\AccountPayables\AccountPayableResource;
use App\Filament\Clusters\Financial\Resources\AccountPayables\RelationManagers\Actions\DeleteInstallmentAction;
use App\Filament\Clusters\Financial\Resources\AccountPayables\RelationManagers\Actions\EditInstallmentAction;
use App\Filament\Clusters\Financial\Resources\AccountPayables\RelationManagers\Actions\RegisterInstallmentPaymentAction;
use App\Filament\Clusters\Sales\Resources\Components\SelectPartner;
use App\Models\AccountPayableInstallment;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\ColumnManagerLayout;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Malzariey\FilamentDaterangepickerFilter\Filters\DateRangeFilter;

class AccountPayableInstallmentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('sequence_number')
            ->columns([
                TextColumn::make('accountPayable.supplier.name')
                    ->label('Fornecedor')
                    ->sortable()
                    ->limit(40)
                    ->url(fn (AccountPayableInstallment $record): ?string => $record->accountPayable
                        ? AccountPayableResource::getUrl('edit', ['record' => $record->accountPayable])
                        : null),
                TextColumn::make('accountPayable.document_number')
                    ->label('Nº Documento')
                    ->searchable()
                    ->sortable()
                    ->placeholder('-'),
                TextColumn::make('description')
                    ->label('Descrição')
                    ->searchable()
                    ->wrap()
                    ->lineClamp(5)
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
                    ->sortable(),
                TextColumn::make('original_amount')
                    ->label('Valor Original')
                    ->money('BRL')
                    ->sortable()
                    ->summarize(Sum::make('original_amount')->money('BRL', 100))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('due_amount')
                    ->label('Valor Atual')
                    ->money('BRL')
                    ->sortable()
                    ->summarize(Sum::make('due_amount')->money('BRL', 100))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('paid_amount')
                    ->label('Valor Pago')
                    ->money('BRL')
                    ->sortable()
                    ->summarize(Sum::make('paid_amount')->money('BRL', 100))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('balance_amount')
                    ->label('Saldo')
                    ->money('BRL')
                    ->sortable()
                    ->summarize(Sum::make('balance_amount')->money('BRL', 100))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('interest_amount')
                    ->label('Juros')
                    ->money('BRL')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('fine_amount')
                    ->label('Multa')
                    ->money('BRL')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('discount_amount')
                    ->label('Desconto')
                    ->money('BRL')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state?->description() ?? '-')
                    ->color(fn ($state) => $state?->color() ?? 'gray')
                    ->sortable(),
                TextColumn::make('paid_date')
                    ->label('Data Pgto.')
                    ->date('d/m/Y')
                    ->placeholder('-')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('financialCategory.full_name')
                    ->label('Categoria')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('notes')
                    ->label('Observações')
                    ->limit(40)
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('due_date', 'asc')
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

                        return $query->whereHas('accountPayable', function (Builder $query) use ($supplierId): Builder {
                            return $query->where('supplier_id', $supplierId);
                        });
                    }),
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(Status::toSelectArray())
                    ->multiple()
                    ->native(false),
                DateRangeFilter::make('due_date')
                    ->label('Vencimento')
                    ->autoApply()
                    ->firstDayOfWeek(0)
                    ->alwaysShowCalendar()
            ])
            ->recordActions([
                ActionGroup::make([
                    RegisterInstallmentPaymentAction::make(),
                    EditInstallmentAction::make(),
                    DeleteInstallmentAction::make(),
                    Action::make('open_account')
                        ->label('Abrir agrupador')
                        ->icon('heroicon-o-folder-open')
                        ->url(fn (AccountPayableInstallment $record): ?string => $record->accountPayable
                            ? AccountPayableResource::getUrl('edit', ['record' => $record->accountPayable])
                            : null),
                ])->icon(Heroicon::Bars3),
            ], RecordActionsPosition::BeforeCells)
            ->toolbarActions([
                Action::make('create_account_payable')
                    ->label('Novo lançamento')
                    ->icon(Heroicon::Plus)
                    ->color('gray')
                    ->url(AccountPayableResource::getUrl('create')),
            ])
            ->emptyStateHeading('Nenhuma parcela encontrada')
            ->emptyStateDescription('As parcelas das contas à pagar aparecerão aqui.')
            ->columnManagerLayout(ColumnManagerLayout::Modal)
            ->columnManagerColumns(2);
    }
}
