<?php

namespace App\Filament\Clusters\Sales\Resources\Requisitions\RelationManagers;

use App\Filament\Clusters\Sales\Resources\Components\ItemValueGroup;
use App\Filament\Clusters\Sales\Resources\Quotes\Schemas\Components\ModalSelectProductStock;
use App\Filament\Clusters\Sales\Resources\Requisitions\RelationManagers\Actions\CreateItemAction;
use App\Filament\Clusters\Sales\Resources\Requisitions\RelationManagers\Actions\DeleteItemAction;
use App\Filament\Clusters\Sales\Resources\Requisitions\RelationManagers\Actions\EditItemAction;
use Filament\Actions\AssociateAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\DissociateAction;
use Filament\Actions\DissociateBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Leandrocfe\FilamentPtbrFormFields\Money;
use Livewire\Attributes\On;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                
            ]);
    }

    #[On('refresh-items')]
    public function refreshItems(): void
    {
        Log::debug('refresh-items');
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('product_id')
            ->heading('Itens da Requisição')
            ->columns([
                TextColumn::make('product.name')
                    ->label('Produto')
                    ->searchable(),
                TextColumn::make('unit_of_measure')
                    ->label('Un.')
                    ->searchable(),
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
                TextColumn::make('unit_cost')
                    ->label('Custo Unitário')
                    ->money('BRL')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
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
                IconColumn::make('stock_consumed')
                    ->label('Estoque Consumido')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('stock_consumed_at')
                    ->label('Dt. Consumo Estoque')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('commission_percentage')
                    ->label('Comissão (%)')
                    ->numeric(2, ',', '.')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('commission_amount')
                    ->label('Vlr. Comissão')
                    ->money('BRL', 100)
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('createdBy.name')
                    ->label('Criado Por')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updatedBy.name')
                    ->label('Atualizado Por')
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
                CreateItemAction::make()
            ])
            ->emptyStateDescription('Adicione itens à requisição para que eles sejam exibidos aqui.');
    }
}
