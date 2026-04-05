<?php

namespace App\Filament\Clusters\Financial\Resources\Invoices\Tables;

use App\Enum\Invoice\Status;
use App\Filament\Clusters\Financial\Resources\Invoices\Pages\Actions\DownloadInvoicePdfAction;
use App\Filament\Clusters\Financial\Resources\Invoices\Pages\Actions\PreviewInvoicePdfAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class InvoicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('invoice_number')
                    ->label('Número')
                    ->searchable()
                    ->sortable()
                    ->icon(Heroicon::Hashtag),
                TextColumn::make('customer.name')
                    ->label('Cliente')
                    ->searchable()
                    ->sortable()
                    ->limit(40),
                TextColumn::make('invoice_date')
                    ->label('Data da Fatura')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->sortable()
                    ->badge()
                    ->formatStateUsing(fn($state) => $state?->description() ?? '-')
                    ->color(fn($state) => $state?->color() ?? 'gray'),
                TextColumn::make('total_amount')
                    ->label('Valor Total')
                    ->money('BRL'),
                TextColumn::make('discount_amount')
                    ->label('Desconto')
                    ->money('BRL')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('createdBy.name')
                    ->label('Criado por')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('confirmed_at')
                    ->label('Confirmado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label('Criado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(Status::toSelectArray())
                    ->multiple()
                    ->native(false),
            ])
            ->recordActions([
                PreviewInvoicePdfAction::make()
                    ->iconButton(),
                DownloadInvoicePdfAction::make()
                    ->iconButton(),
                EditAction::make()
                    ->iconButton(),
                DeleteAction::make()
                    ->iconButton(),
            ])
            ->toolbarActions([
                DeleteBulkAction::make(),
            ]);
    }
}
