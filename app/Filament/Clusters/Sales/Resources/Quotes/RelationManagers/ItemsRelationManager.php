<?php

namespace App\Filament\Clusters\Sales\Resources\Quotes\RelationManagers;

use App\Enum\Quote\Destination;
use App\Enum\Quote\Status;
use App\Filament\Clusters\Sales\Resources\Quotes\RelationManagers\Actions\CreateItemAction;
use App\Filament\Clusters\Sales\Resources\Quotes\RelationManagers\Actions\DeleteItemAction;
use App\Filament\Clusters\Sales\Resources\Quotes\RelationManagers\Actions\EditItemAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->heading('Itens do Orçamento')
            ->columns([
                TextColumn::make('id')
                    ->label('#')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('identifier')
                    ->label('Produto/Serviço'),
                TextColumn::make('unit_of_measure')
                    ->label('Un.'),
                TextColumn::make('quantity')
                    ->label('Qtde.')
                    ->numeric(2, ',', '.')
                    ->sortable()
                    ->summarize(Sum::make('quantity')->label('TT Qtde.')),
                TextColumn::make('unit_price')
                    ->label('Vlr. Unitário')
                    ->money('BRL')
                    ->sortable(),
                TextColumn::make('gross_amount')
                    ->label('Vlr. Bruto')
                    ->state(fn ($record): float => round(((float) ($record->quantity ?? 0)) * ((float) ($record->unit_price ?? 0)), 2))
                    ->money('BRL')
                    ->summarize(
                        Sum::make('gross_amount')
                            ->label('TT Bruto')
                            ->using(fn ($query): float => (float) $query->sum(DB::raw('quantity * unit_price')))
                            ->money('BRL', 100)
                    ),
                TextColumn::make('discount_percentage')
                    ->label('Desc. (%)')
                    ->numeric(2, ',', '.')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('discount_amount')
                    ->label('Desc. (R$)')
                    ->money('BRL')
                    ->sortable()
                    ->summarize(Sum::make('discount_amount')->label('TT Desconto')->money('BRL', 100)),
                TextColumn::make('total_amount')
                    ->label('Total Líquido')
                    ->money('BRL')
                    ->sortable()
                    ->summarize(Sum::make('total_amount')->label('TT Líquido')->money('BRL', 100)),
                TextColumn::make('destination')
                    ->label('Finalidade')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state->descriptionAbbreviated())
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state->description())
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('estimated_production_hours')
                    ->label('Hrs. Produção')
                    ->numeric(2, ',', '.')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('material_cost')
                    ->label('Custo Material')
                    ->money('BRL', 100)
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('labor_cost')
                    ->label('Custo MO')
                    ->money('BRL', 100)
                    ->sortable()
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
            ->filters([
            ])
            ->headerActions([
                
            ])
            ->recordActions([
                EditItemAction::make()
                    ->iconButton(),
                DeleteItemAction::make()
                    ->iconButton(),
            ])
            ->toolbarActions([
                CreateItemAction::make(),
            ])
            ->emptyStateDescription('Adicione itens ao orçamento para que eles sejam exibidos aqui.');
    }
}
