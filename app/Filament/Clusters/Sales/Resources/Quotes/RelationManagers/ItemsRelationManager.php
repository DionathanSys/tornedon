<?php

namespace App\Filament\Clusters\Sales\Resources\Quotes\RelationManagers;

use App\Models\QuoteItem;
use Filament\Actions\AssociateAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\DissociateAction;
use Filament\Actions\DissociateBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('product_id')
                    ->relationship('product', 'name'),
                Textarea::make('description')
                    ->required()
                    ->columnSpanFull(),
                TextInput::make('quantity')
                    ->required()
                    ->numeric()
                    ->default(1.0),
                TextInput::make('unit_of_measure')
                    ->required()
                    ->default('UN'),
                TextInput::make('unit_price')
                    ->required()
                    ->numeric()
                    ->default(0.0)
                    ->prefix('$'),
                TextInput::make('discount_percentage')
                    ->required()
                    ->numeric()
                    ->default(0.0),
                TextInput::make('discount_amount')
                    ->required()
                    ->numeric()
                    ->default(0.0),
                TextInput::make('total_amount')
                    ->numeric(),
                TextInput::make('technical_specifications'),
                TextInput::make('estimated_production_hours')
                    ->numeric(),
                TextInput::make('material_cost')
                    ->numeric()
                    ->prefix('$'),
                TextInput::make('labor_cost')
                    ->numeric()
                    ->prefix('$'),
                TextInput::make('sequence')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('additional_info'),
                Select::make('service_id')
                    ->relationship('service', 'name'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                TextColumn::make('product.name')
                    ->searchable(),
                TextColumn::make('quantity')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('unit_of_measure')
                    ->searchable(),
                TextColumn::make('unit_price')
                    ->money()
                    ->sortable(),
                TextColumn::make('discount_percentage')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('discount_amount')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('total_amount')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('estimated_production_hours')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('material_cost')
                    ->money()
                    ->sortable(),
                TextColumn::make('labor_cost')
                    ->money()
                    ->sortable(),
                TextColumn::make('sequence')
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
                TextColumn::make('service.name')
                    ->searchable(),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                CreateAction::make(),
                AssociateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DissociateAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DissociateBulkAction::make(),
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public function createAction(): CreateAction
    {
        return CreateAction::make()
            ->using(function (array $data, RelationManager $livewire) {
                $service = app(\App\Services\QuoteItem\QuoteItemService::class);
                $data['quote_id'] = $livewire->getOwnerRecord()->id;
                $item = $service->create($data, Auth::id());
                if (! $item) {
                    throw new \Exception($service->getMessage() ?? 'Erro ao criar item');
                }
                return $item;
            });
    }

    public function editAction(): EditAction
    {
        return EditAction::make()
            ->using(function (array $data, QuoteItem $record) {
                $service = app(\App\Services\QuoteItem\QuoteItemService::class);
                $item = $service->update($record, $data, Auth::id());
                if (! $item) {
                    throw new \Exception($service->getMessage() ?? 'Erro ao atualizar item');
                }
                return $item;
            });
    }

    public function deleteAction(): DeleteAction
    {
        return DeleteAction::make()
            ->using(function (QuoteItem $record) {
                $service = app(\App\Services\QuoteItem\QuoteItemService::class);
                $ok = $service->delete($record, Auth::id());
                if (! $ok) {
                    throw new \Exception($service->getMessage() ?? 'Erro ao excluir item');
                }
                return $ok;
            });
    }
}
