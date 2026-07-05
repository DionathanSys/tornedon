<?php

namespace App\Filament\Clusters\Sales\Resources\ProductionRequests\RelationManagers;

use App\Enum\ProductionRequest\Status;
use App\Models\Product;
use App\Models\ProductionRequest;
use App\Models\ProductionRequestItem;
use App\Notification\NotifyService as notify;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Leandrocfe\FilamentPtbrFormFields\Money;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Itens do Pedido')
            ->columns([
                TextColumn::make('product.name')
                    ->label('Produto')
                    ->searchable()
                    ->description(fn (ProductionRequestItem $record): string => sprintf(
                        '%s x %s',
                        number_format((float) $record->quantity, 3, ',', '.'),
                        $record->unit_of_measure,
                    )),
                TextColumn::make('quantity')
                    ->label('Qtde.')
                    ->numeric(3, ',', '.')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('unit_price')
                    ->label('Vlr. Unitário')
                    ->money('BRL')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('total_amount')
                    ->label('Total')
                    ->money('BRL'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Item')
                    ->visible(fn (): bool => $this->ownerProductionRequest()->status === Status::OPEN)
                    ->schema($this->itemSchema())
                    ->using(function (array $data): ProductionRequestItem {
                        $record = $this->ownerProductionRequest();

                        return $record->items()->create([
                            ...$data,
                            'discount_percentage' => 0,
                            'discount_amount' => 0,
                            'created_by' => Auth::id(),
                            'updated_by' => Auth::id(),
                            'sequence' => ((int) $record->items()->max('sequence')) + 1,
                        ]);
                    })
                    ->after(fn () => notify::success('Item adicionado com sucesso.')),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn (): bool => $this->ownerProductionRequest()->status === Status::OPEN)
                    ->schema($this->itemSchema())
                    ->using(function (ProductionRequestItem $record, array $data): ProductionRequestItem {
                        $record->update([
                            ...$data,
                            'discount_percentage' => 0,
                            'discount_amount' => 0,
                            'updated_by' => Auth::id(),
                        ]);

                        return $record->refresh();
                    })
                    ->after(fn () => notify::success('Item atualizado com sucesso.')),
                DeleteAction::make()
                    ->visible(fn (): bool => $this->ownerProductionRequest()->status === Status::OPEN),
            ])
            ->emptyStateDescription('Adicione itens para compor o pedido para produção.');
    }

    /**
     * @return array<int, mixed>
     */
    private function itemSchema(): array
    {
        return [
            Select::make('product_id')
                ->label('Produto')
                ->options(fn (): array => Product::query()
                    ->where('company_id', Filament::getTenant()->id)
                    ->where('is_active', true)
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->toArray())
                ->searchable()
                ->preload()
                ->native(false)
                ->required()
                ->live()
                ->afterStateUpdated(function (Set $set, $state): void {
                    $product = filled($state)
                        ? Product::query()->where('company_id', Filament::getTenant()->id)->find((int) $state)
                        : null;

                    if (! $product) {
                        return;
                    }

                    $set('unit_of_measure', $product->unit?->value ?? 'UN');
                    $set('unit_price', round((float) ($product->sale_price_value ?? 0), 2));
                }),
            TextInput::make('unit_of_measure')
                ->label('Unidade')
                ->required()
                ->maxLength(10),
            TextInput::make('quantity')
                ->label('Quantidade')
                ->numeric()
                ->minValue(0.001)
                ->required(),
            Money::make('unit_price')
                ->label('Preço Unitário')
                ->required(),
        ];
    }

    private function ownerProductionRequest(): ProductionRequest
    {
        /** @var ProductionRequest $record */
        $record = parent::getOwnerRecord();

        return $record;
    }
}
