<?php

namespace App\Filament\Clusters\Manufacturing\Resources\ProductionOrders\RelationManagers;

use App\Filament\Clusters\Sales\Resources\Components\ItemValueGroup;
use App\Filament\Clusters\Sales\Resources\Quotes\Schemas\Components\ModalSelectProductStock;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                ModalSelectProductStock::make('product_id')
                    ->label('Produto')
                    ->required(),
                ItemValueGroup::make(),
                Textarea::make('description')
                    ->columnSpanFull(),
                TextInput::make('quantity')
                    ->required()
                    ->numeric()
                    ->default(1.0),
                TextInput::make('quantity_produced')
                    ->required()
                    ->numeric()
                    ->default(0.0),
                TextInput::make('quantity_approved')
                    ->required()
                    ->numeric()
                    ->default(0.0),
                TextInput::make('quantity_rejected')
                    ->required()
                    ->numeric()
                    ->default(0.0),
                TextInput::make('unit_of_measure')
                    ->required()
                    ->default('UN'),
                TextInput::make('technical_specifications'),
                Textarea::make('production_notes')
                    ->columnSpanFull(),
                Textarea::make('qc_notes')
                    ->columnSpanFull(),
                TextInput::make('actual_production_hours')
                    ->numeric(),
                TextInput::make('sequence')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('additional_info'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('product_id')
            ->columns([
                TextColumn::make('quoteItem.id')
                    ->label('Item Orçamento')
                    ->searchable(),
                TextColumn::make('product.name')
                    ->label('Produto')
                    ->searchable(),
                TextColumn::make('description')
                    ->label('Descrição')
                    ->limit(50)
                    ->toggleable(),
                TextColumn::make('quantity')
                    ->label('Qtd. Planejada')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('quantity_produced')
                    ->label('Qtd. Produzida')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('quantity_approved')
                    ->label('Qtd. Aprovada')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('quantity_rejected')
                    ->label('Qtd. Rejeitada')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('unit_of_measure')
                    ->label('Unidade')
                    ->searchable(),
                TextColumn::make('actual_production_hours')
                    ->label('Horas')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('sequence')
                    ->label('Seq.')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
