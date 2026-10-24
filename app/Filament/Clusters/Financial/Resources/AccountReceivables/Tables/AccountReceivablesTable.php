<?php

namespace App\Filament\Clusters\Financial\Resources\AccountReceivables\Tables;

use App\Enum\AccountReceivable\Status;
use App\Filament\Clusters\Financial\Actions\IgnoreFinancialAction;
use App\Filament\Clusters\Financial\Resources\AccountReceivables\AccountReceivableResource;
use App\Filament\Clusters\Financial\Resources\Components\AccountSummary;
use App\Filament\Clusters\Financial\Resources\Components\FinancialTableColumns;
use App\Models\FinancialCategory;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AccountReceivablesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => AccountSummary::query($query))
            ->columns([
                TextColumn::make('consideration')->label('No módulo')->state(fn ($record) => $record->ignored_count > 0
                    ? ((float) $record->considered_balance > 0 ? 'Parcialmente ignorada' : 'Ignorada') : 'Considerada')->badge(),
                TextColumn::make('counterparty_label')
                    ->label('Cliente')
                    ->description(fn ($record): ?string => $record->description)
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->where(function (Builder $query) use ($search): void {
                            $query->whereHas('customer', fn (Builder $customerQuery) => $customerQuery->where('name', 'like', "%{$search}%"))
                                ->orWhere('manual_counterparty_name', 'like', "%{$search}%")
                                ->orWhere('description', 'like', "%{$search}%");
                        });
                    })
                    ->sortable(query: fn (Builder $query, string $direction) => $query->orderByRaw('COALESCE((SELECT name FROM partners WHERE partners.id = account_receivables.customer_id AND partners.deleted_at IS NULL), manual_counterparty_name, ?) '.($direction === 'desc' ? 'desc' : 'asc'), ['']))
                    ->limit(40),
                TextColumn::make('document_number')
                    ->label('Nº Documento')
                    ->searchable()
                    ->sortable()
                    ->placeholder('-'),
                TextColumn::make('next_due_date')
                    ->label('Próximo vencimento aberto')
                    ->state(fn ($record) => $record->next_open_due_date)
                    ->date('d/m/Y'),
                TextColumn::make('open_balance')
                    ->label('Saldo considerado')
                    ->state(fn ($record) => (float) $record->considered_balance / 100)
                    ->money('BRL'),
                TextColumn::make('status')
                    ->label('Status')
                    ->sortable()
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state?->description() ?? '-')
                    ->color(fn ($state) => $state?->color() ?? 'gray'),
                TextColumn::make('due_amount')
                    ->label('Valor')
                    ->money('BRL')
                    ->sortable(),
                TextColumn::make('paid_amount')
                    ->label('Valor Recebido')
                    ->money('BRL')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                IconColumn::make('paid')
                    ->label('Recebido')
                    ->boolean()
                    ->sortable(),
                TextColumn::make('paid_date')
                    ->label('Data Receb.')
                    ->date('d/m/Y')
                    ->sortable()
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('payment_method')
                    ->label('Forma Pgto.')
                    ->formatStateUsing(fn ($state) => $state?->description() ?? '-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label('Criado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('due_date', 'asc')
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(Status::toSelectArray())
                    ->multiple()
                    ->native(false),
                SelectFilter::make('financial_category_id')
                    ->label('Categoria')
                    ->options(fn (): array => FinancialCategory::optionsForCompany(Filament::getTenant()->id, 'receivable'))
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $query, int $categoryId): Builder => $query->whereHas(
                            'installments',
                            fn (Builder $installmentsQuery): Builder => $installmentsQuery->where('financial_category_id', $categoryId),
                        ),
                    ))
                    ->searchable()
                    ->native(false),
            ])
            ->recordActions([
                EditAction::make()
                    ->iconButton(),
                ViewAction::make()->iconButton(),
                IgnoreFinancialAction::make(),
                IgnoreFinancialAction::make(restore: true),
                DeleteAction::make()
                    ->iconButton(),
            ])
            ->toolbarActions([
                CreateAction::make()
                    ->label('Conta a Receber')
                    ->icon(Heroicon::Plus)
                    ->size(Size::Small),
            ])
            ->recordUrl(fn ($record) => AccountReceivableResource::getUrl('view', ['record' => $record]))
            ->bulkActions([
                DeleteBulkAction::make(),
            ])
            ->tap(fn (Table $table) => FinancialTableColumns::configure($table));
    }
}
