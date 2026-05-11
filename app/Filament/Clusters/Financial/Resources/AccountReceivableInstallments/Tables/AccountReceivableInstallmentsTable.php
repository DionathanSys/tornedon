<?php

namespace App\Filament\Clusters\Financial\Resources\AccountReceivableInstallments\Tables;

use App\Enum\AccountReceivable\Status;
use App\Filament\Clusters\Financial\Resources\AccountReceivables\AccountReceivableResource;
use App\Filament\Clusters\Financial\Resources\AccountReceivables\RelationManagers\Actions\DeleteInstallmentAction;
use App\Filament\Clusters\Financial\Resources\AccountReceivables\RelationManagers\Actions\EditInstallmentAction;
use App\Filament\Clusters\Financial\Resources\AccountReceivables\RelationManagers\Actions\RegisterInstallmentPaymentAction;
use App\Models\AccountReceivableInstallment;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\ColumnManagerLayout;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Malzariey\FilamentDaterangepickerFilter\Filters\DateRangeFilter;

class AccountReceivableInstallmentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('sequence_number')
            ->columns([
                TextColumn::make('accountReceivable.customer.name')
                    ->label('Cliente')
                    ->searchable()
                    ->sortable()
                    ->limit(40)
                    ->url(fn (AccountReceivableInstallment $record): ?string => $record->accountReceivable
                        ? AccountReceivableResource::getUrl('edit', ['record' => $record->accountReceivable])
                        : null),
                TextColumn::make('accountReceivable.document_number')
                    ->label('Nº Documento')
                    ->searchable()
                    ->sortable()
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('description')
                    ->label('Descrição')
                    ->searchable()
                    ->limit(50)
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
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('balance_amount')
                    ->label('Saldo')
                    ->money('BRL')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('status')
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
                    ->defaultLast7Days(),
            ])
            ->recordActions([
                ActionGroup::make([
                    RegisterInstallmentPaymentAction::make(),
                EditInstallmentAction::make(),
                DeleteInstallmentAction::make(),
                Action::make('open_account')
                    ->label('Abrir agrupador')
                    ->icon('heroicon-o-folder-open')
                    ->url(fn (AccountReceivableInstallment $record): ?string => $record->accountReceivable
                        ? AccountReceivableResource::getUrl('edit', ['record' => $record->accountReceivable])
                        : null),])->icon(Heroicon::Bars3)
                ], RecordActionsPosition::BeforeCells)
            ->toolbarActions([
                Action::make('create_account_receivable')
                    ->label('Novo lancamento')
                    ->icon('heroicon-o-plus')
                    ->color('gray')
                    ->url(AccountReceivableResource::getUrl('create')),
            ])
            ->emptyStateHeading('Nenhuma parcela encontrada')
            ->emptyStateDescription('As parcelas das contas a receber aparecerao aqui.')
            ->columnManagerLayout(ColumnManagerLayout::Modal)
            ->columnManagerColumns(2);
    }
}
