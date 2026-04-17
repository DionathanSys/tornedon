<?php

namespace App\Filament\Clusters\Financial\Resources\AccountReceivableInstallments\Tables;

use App\Enum\AccountReceivable\Status;
use App\Filament\Clusters\Financial\Resources\AccountReceivables\AccountReceivableResource;
use App\Filament\Clusters\Financial\Resources\AccountReceivables\RelationManagers\Actions\DeleteInstallmentAction;
use App\Filament\Clusters\Financial\Resources\AccountReceivables\RelationManagers\Actions\EditInstallmentAction;
use App\Filament\Clusters\Financial\Resources\AccountReceivables\RelationManagers\Actions\RegisterInstallmentPaymentAction;
use App\Models\AccountReceivableInstallment;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
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
                    ->placeholder('-'),
                TextColumn::make('description')
                    ->label('Descricao')
                    ->searchable()
                    ->limit(50)
                    ->placeholder('-'),
                TextColumn::make('sequence_number')
                    ->label('Parcela')
                    ->badge()
                    ->sortable(),
                TextColumn::make('due_date')
                    ->label('Vencimento')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('due_amount')
                    ->label('Valor Atual')
                    ->money('BRL')
                    ->sortable(),
                TextColumn::make('received_amount')
                    ->label('Valor Recebido')
                    ->money('BRL')
                    ->sortable(),
                TextColumn::make('balance_amount')
                    ->label('Saldo')
                    ->money('BRL')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state?->description() ?? '-')
                    ->color(fn ($state) => $state?->color() ?? 'gray')
                    ->sortable(),
                TextColumn::make('received_date')
                    ->label('Data Receb.')
                    ->date('d/m/Y')
                    ->placeholder('-')
                    ->sortable(),
                TextColumn::make('financialCategory.full_name')
                    ->label('Categoria')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('due_date', 'asc')
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
                    ->alwaysShowCalendar(),
            ])
            ->recordActions([
                RegisterInstallmentPaymentAction::make()
                    ->iconButton(),
                EditInstallmentAction::make()
                    ->iconButton(),
                DeleteInstallmentAction::make()
                    ->iconButton(),
                Action::make('open_account')
                    ->label('Abrir agrupador')
                    ->icon('heroicon-o-folder-open')
                    ->iconButton()
                    ->url(fn (AccountReceivableInstallment $record): ?string => $record->accountReceivable
                        ? AccountReceivableResource::getUrl('edit', ['record' => $record->accountReceivable])
                        : null),
            ])
            ->toolbarActions([
                Action::make('create_account_receivable')
                    ->label('Novo lancamento')
                    ->icon('heroicon-o-plus')
                    ->url(AccountReceivableResource::getUrl('create')),
            ])
            ->emptyStateHeading('Nenhuma parcela encontrada')
            ->emptyStateDescription('As parcelas das contas a receber aparecerao aqui.');
    }
}
