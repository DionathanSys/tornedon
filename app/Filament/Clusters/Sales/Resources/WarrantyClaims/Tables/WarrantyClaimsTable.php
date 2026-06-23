<?php

namespace App\Filament\Clusters\Sales\Resources\WarrantyClaims\Tables;

use App\Enum\WarrantyClaim\Status;
use App\Enum\WarrantyClaim\Type;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class WarrantyClaimsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')
                    ->label('Número')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('type')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn (Type $state): string => $state->description())
                    ->color(fn (Type $state): string => $state->color())
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (Status $state): string => $state->description())
                    ->color(fn (Status $state): string => $state->color())
                    ->sortable(),
                TextColumn::make('customer.name')
                    ->label('Cliente')
                    ->searchable()
                    ->limit(35),
                TextColumn::make('supplier.name')
                    ->label('Fornecedor')
                    ->placeholder('-')
                    ->searchable()
                    ->limit(35),
                TextColumn::make('product.name')
                    ->label('Produto')
                    ->placeholder('-')
                    ->searchable()
                    ->limit(35),
                IconColumn::make('advanced_replacement')
                    ->label('Troca antecipada')
                    ->boolean(),
                TextColumn::make('supplier_protocol')
                    ->label('Protocolo')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('originServiceOrder.number')
                    ->label('OS origem')
                    ->placeholder('-'),
                TextColumn::make('originRequisition.number')
                    ->label('Req. origem')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('expires_at')
                    ->label('Validade')
                    ->date('d/m/Y')
                    ->placeholder('-'),
                TextColumn::make('closed_at')
                    ->label('Encerrado em')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label('Tipo')
                    ->options(Type::toSelectArray())
                    ->native(false),
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(Status::toSelectArray())
                    ->native(false),
            ])
            ->recordActions([
                EditAction::make()->iconButton(),
            ])
            ->toolbarActions([
                CreateAction::make()
                    ->icon(Heroicon::Plus)
                    ->label('Garantia')
                    ->size(Size::Small),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
