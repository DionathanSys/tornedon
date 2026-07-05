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
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Itens do Pedido')
            ->columns([
                TextColumn::make('sequence')
                    ->label('#')
                    ->sortable(),
                TextColumn::make('product.name')
                    ->label('Produto')
                    ->searchable(),
                TextColumn::make('unit_of_measure')
                    ->label('Un.'),
                TextColumn::make('quantity')
                    ->label('Qtde.')
                    ->numeric(3, ',', '.'),
                TextColumn::make('unit_price')
                    ->label('Vlr. Unitário')
                    ->money('BRL'),
                TextColumn::make('discount_amount')
                    ->label('Desc.')
                    ->money('BRL'),
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
                            'created_by' => Auth::id(),
                            'updated_by' => Auth::id(),
                            'sequence' => (int) ($data['sequence'] ?? (((int) $record->items()->max('sequence')) + 1)),
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
                ->afterStateUpdated(function (Set $set, Get $get, $state): void {
                    $product = filled($state)
                        ? Product::query()->where('company_id', Filament::getTenant()->id)->find((int) $state)
                        : null;

                    if (! $product) {
                        return;
                    }

                    $set('description', $get('description') ?: $product->name);
                    $set('unit_of_measure', $product->unit?->value ?? 'UN');
                    $set('unit_price', round((float) ($product->sale_price_value ?? 0), 2));
                }),
            Textarea::make('description')
                ->label('Descrição')
                ->rows(2)
                ->columnSpanFull(),
            TextInput::make('unit_of_measure')
                ->label('Unidade')
                ->required()
                ->maxLength(10),
            TextInput::make('quantity')
                ->label('Quantidade')
                ->numeric()
                ->minValue(0.001)
                ->required(),
            TextInput::make('unit_price')
                ->label('Preço Unitário')
                ->numeric()
                ->minValue(0)
                ->required(),
            TextInput::make('discount_percentage')
                ->label('Desconto (%)')
                ->numeric()
                ->minValue(0)
                ->default(0),
            TextInput::make('discount_amount')
                ->label('Desconto (R$)')
                ->numeric()
                ->minValue(0)
                ->default(0),
            TextInput::make('sequence')
                ->label('Sequência')
                ->numeric()
                ->default(fn (): int => ((int) $this->ownerProductionRequest()->items()->max('sequence')) + 1)
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
