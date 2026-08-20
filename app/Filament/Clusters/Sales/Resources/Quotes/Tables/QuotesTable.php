<?php

namespace App\Filament\Clusters\Sales\Resources\Quotes\Tables;

use App\Enum\Quote\Status;
use App\Filament\Clusters\Sales\Resources\Quotes\Pages\Actions\DownloadQuotePdfAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Summarizer;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class QuotesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('quote_number')
                    ->label('Número')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('customer.name')
                    ->label('Cliente')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->colors([
                        'gray' => Status::DRAFT->value,
                        'info' => Status::SENT->value,
                        'success' => Status::APPROVED->value,
                        'danger' => Status::REJECTED->value,
                        'warning' => Status::EXPIRED->value,
                    ])
                    ->formatStateUsing(fn ($state) => $state->description())
                    ->sortable(),
                TextColumn::make('total_amount')
                    ->label('Valor Total')
                    ->money('BRL', 1)
                    ->summarize(
                        Summarizer::make()
                            ->label('Total')
                            ->money('BRL', 1)
                            ->using(fn (Builder $query): float => self::resolveSummaryTotal($query))
                    )
                    ->alignEnd(),
                TextColumn::make('valid_until')
                    ->label('Válido até')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Criado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('approved_at')
                    ->label('Aprovado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('createdBy.name')
                    ->label('Criado por')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('customer_id')
                    ->label('Cliente')
                    ->relationship('customer', 'name')
                    ->searchable()
                    ->preload()
                    ->native(false),
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(Status::toSelectArray())
                    ->default([Status::DRAFT->value, Status::SENT->value, Status::EXPIRED->value])
                    ->multiple()
                    ->native(false),
            ])
            ->recordActions([
                DownloadQuotePdfAction::make()
                    ->iconButton()
                    ->tooltip('Baixar PDF do orçamento'),
                EditAction::make()
                    ->iconButton(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
                CreateAction::make()
                    ->label('Orçamento')
                    ->icon(Heroicon::Plus)
                    ->size(Size::Small),
            ])
            ->defaultSort('created_at', 'desc');
    }

    private static function resolveSummaryTotal(Builder $query): float
    {
        $filteredQuotes = DB::query()->fromSub(
            (clone $query)->select('quotes.id'),
            'filtered_quotes'
        );

        $itemTotals = DB::table('quote_items')
            ->selectRaw('
                quote_id,
                COALESCE(SUM(total_amount), 0) as total_amount
            ')
            ->groupBy('quote_id');

        $totals = $filteredQuotes
            ->leftJoinSub($itemTotals, 'item_totals', 'item_totals.quote_id', '=', 'filtered_quotes.id')
            ->selectRaw('COALESCE(SUM(COALESCE(item_totals.total_amount, 0)), 0) as total_amount')
            ->first();

        return round((float) ($totals->total_amount ?? 0), 2);
    }
}
