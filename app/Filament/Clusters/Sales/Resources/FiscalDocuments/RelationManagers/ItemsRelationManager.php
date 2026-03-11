<?php

namespace App\Filament\Clusters\Sales\Resources\FiscalDocuments\RelationManagers;

use App\Filament\Clusters\Sales\Resources\FiscalDocuments\RelationManagers\Actions\CreateItemAction;
use App\Filament\Clusters\Sales\Resources\FiscalDocuments\RelationManagers\Actions\DeleteItemAction;
use App\Filament\Clusters\Sales\Resources\FiscalDocuments\RelationManagers\Actions\EditItemAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('description')
            ->heading('Itens da Nota Fiscal')
            ->columns([
                TextColumn::make('item_number')
                    ->label('Nº')
                    ->sortable(),
                TextColumn::make('product.name')
                    ->label('Produto')
                    ->searchable()
                    ->limit(40),
                TextColumn::make('description')
                    ->label('Descrição')
                    ->searchable()
                    ->limit(40)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('ncm_code')
                    ->label('NCM')
                    ->searchable(),
                TextColumn::make('cfop_code')
                    ->label('CFOP')
                    ->searchable(),
                TextColumn::make('unit_of_measure')
                    ->label('Un.'),
                TextColumn::make('quantity')
                    ->label('Qtde.')
                    ->numeric(4, ',', '.')
                    ->sortable()
                    ->summarize(Sum::make('quantity')->label('TT Qtde.')),
                TextColumn::make('unit_price')
                    ->label('Vlr. Unitário')
                    ->money('BRL')
                    ->sortable(),
                TextColumn::make('discount_amount')
                    ->label('Desconto')
                    ->money('BRL')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->summarize(Sum::make('discount_amount')->label('TT Desconto')->money('BRL', 100)),
                TextColumn::make('total_price')
                    ->label('Total')
                    ->money('BRL')
                    ->sortable()
                    ->summarize(Sum::make('total_price')->label('TT Total')->money('BRL', 100)),
                TextColumn::make('freight_amount')
                    ->label('Frete')
                    ->money('BRL')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('insurance_amount')
                    ->label('Seguro')
                    ->money('BRL')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('other_expenses_amount')
                    ->label('Outras Desp.')
                    ->money('BRL')
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('included_in_total')
                    ->label('No Total')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label('Criado Em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label('Atualizado Em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([])
            ->headerActions([])
            ->recordActions([
                EditItemAction::make()
                    ->iconButton(),
                DeleteItemAction::make()
                    ->iconButton(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
                CreateItemAction::make(),
            ])
            ->emptyStateDescription('Adicione itens à nota fiscal para que sejam exibidos aqui.');
    }
}
