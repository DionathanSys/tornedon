<?php

namespace App\Filament\Clusters\Sales\Resources\Quotes\RelationManagers\Actions;

use App\Filament\Clusters\Inventory\Resources\Products\Tables\ProductsTable;
use App\Filament\Clusters\Sales\Resources\Components\SelectProduct;
use App\Filament\Tables\ProductsStockTable;
use App\Filament\Tables\ProductTable;
use App\Filament\Tables\ServiceTable;
use App\Models\QuoteItem;
use App\Notification\NotifyService as notify;
use App\Services\Product\ProductSalePriceService;
use App\Services\Product\ProductService;
use App\Services\QuoteItem\QuoteItemService;
use App\Services\Service\ServiceService;
use App\Traits\AuthorizesQuoteItemActions;
use App\Traits\ParsesMoneyValues;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\ModalTableSelect;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Leandrocfe\FilamentPtbrFormFields\Money;

final class EditItemAction
{
    use AuthorizesQuoteItemActions;
    use ParsesMoneyValues;

    public static function make(): EditAction
    {
        return EditAction::make()
            ->label('Editar')
            ->visible(fn(RelationManager $livewire): bool => self::canModifyQuoteItems($livewire->getOwnerRecord()))
            ->schema([
                Group::make()
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        ModalTableSelect::make('product_stock_id')
                            ->label('Produto Em Estoque')
                            ->relationship('productStock', 'name')
                            ->tableConfiguration(ProductsStockTable::class)
                            ->selectAction(
                                fn(Action $action) => $action
                                    ->label('Selecionar')
                                    ->modalHeading('Buscar Produto')
                                    ->modalSubmitActionLabel('Confirmar seleção'),
                            )
                            ->afterStateUpdated(function ($state, Set $set, Get $get) {
                                if ($state) {
                                    $salePrice = app(ProductSalePriceService::class)->resolve($state);
                                    $unitOfMeasure = app(ProductService::class)->getUnitOfMeasure($state);
                                    $set('service_id', null);
                                    $set('product_id', null);
                                    $set('unit_of_measure', $unitOfMeasure);
                                    $set('unit_price', number_format($salePrice, 2, ',', '.'));
                                    $set('discount_amount', number_format(0, 2, ',', '.'));
                                    $set('discount_percentage', number_format(0, 2, ',', '.'));
                                    self::calculateValues($get, $set);
                                }
                            }),
                        ModalTableSelect::make('product_id')
                            ->label('Produto')
                            ->relationship('product', 'name')
                            ->tableConfiguration(ProductTable::class)
                            ->selectAction(
                                fn(Action $action) => $action
                                    ->label('Selecionar')
                                    ->modalHeading('Buscar Produto')
                                    ->modalSubmitActionLabel('Confirmar seleção'),
                            )
                            ->afterStateUpdated(function ($state, Set $set, Get $get) {
                                if ($state) {
                                    $salePrice = app(ProductSalePriceService::class)->resolve($state);
                                    $unitOfMeasure = app(ProductService::class)->getUnitOfMeasure($state);
                                    $set('service_id', null);
                                    $set('product_stock_id', null);
                                    $set('unit_of_measure', $unitOfMeasure);
                                    $set('unit_price', number_format($salePrice, 2, ',', '.'));
                                    $set('discount_amount', number_format(0, 2, ',', '.'));
                                    $set('discount_percentage', number_format(0, 2, ',', '.'));
                                    self::calculateValues($get, $set);
                                }
                            }),
                        ModalTableSelect::make('service_id')
                            ->label('Serviço')
                            ->relationship('service', 'name')
                            ->tableConfiguration(ServiceTable::class)
                            ->selectAction(
                                fn(Action $action) => $action
                                    ->label('Selecionar')
                                    ->modalHeading('Buscar Serviço')
                                    ->modalSubmitActionLabel('Confirmar seleção'),
                            )
                            ->afterStateUpdated(function ($state, Set $set, Get $get) {
                                if ($state) {
                                    $salePrice = app(ServiceService::class)->getSalePrice($state);
                                    $set('product_id', null);
                                    $set('product_stock_id', null);
                                    $set('unit_of_measure', null);
                                    $set('unit_price', number_format($salePrice, 2, ',', '.'));
                                    $set('discount_amount', number_format(0, 2, ',', '.'));
                                    $set('discount_percentage', number_format(0, 2, ',', '.'));
                                    self::calculateValues($get, $set);
                                }
                            }),
                    ]),
                Group::make()
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('quantity')
                            ->label('Quantidade')
                            ->required()
                            ->numeric()
                            ->default(1)
                            ->minValue(0)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function ($state, Set $set, Get $get) {
                                $set('discount_amount', number_format(0, 2, ',', '.'));
                                $set('discount_percentage', number_format(0, 2, ',', '.'));
                                self::calculateValues($get, $set);
                            }),
                        Money::make('unit_price')
                            ->label('Preço Unitário')
                            ->required()
                            ->live(onBlur: true)
                            ->afterStateUpdated(function ($state, Set $set, Get $get) {
                                $set('discount_amount', number_format(0, 2, ',', '.'));
                                $set('discount_percentage', number_format(0, 2, ',', '.'));
                                self::calculateValues($get, $set);
                            }),
                        Money::make('subtotal')
                            ->label('Subtotal')
                            ->readOnly(),
                    ]),
                Group::make()
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        Money::make('discount_percentage')
                            ->label('Desconto (%)')
                            ->suffix('%')
                            ->prefix(null)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function ($state, Set $set, callable $get) {
                                $subtotal = self::parseMoneyValue($get('subtotal'));
                                $percentage = self::parseMoneyValue($state);
                                $discountAmount = $subtotal * ($percentage / 100);
                                $set('discount_amount', number_format($discountAmount, 2, ',', '.'));
                                self::calculateValues($get, $set);
                            })
                            ->afterLabel(Action::make('reset_discount_percentage')
                                ->label('')
                                ->icon(Heroicon::ArrowPath)
                                ->action(function (Set $set, Get $get) {
                                    $set('discount_percentage', number_format(0, 2, ',', '.'));
                                    $set('discount_amount', number_format(0, 2, ',', '.'));
                                    self::calculateValues($get, $set);
                                })),
                        Money::make('discount_amount')
                            ->label('Desconto (R$)')
                            ->live(onBlur: true)
                            ->afterStateUpdated(function ($state, Set $set, callable $get) {
                                $subtotal = self::parseMoneyValue($get('subtotal'));
                                $discountAmount = self::parseMoneyValue($state);
                                if ($subtotal > 0) {
                                    $percentage = ($discountAmount / $subtotal) * 100;
                                    $set('discount_percentage', number_format($percentage, 2, ',', '.'));
                                }
                                self::calculateValues($get, $set);
                            }),
                        Money::make('total_amount')
                            ->label('Valor Total')
                            ->readOnly(),
                    ]),
                Textarea::make('observations')
                    ->label('Observações')
                    ->columnSpanFull(),
            ])
            ->using(function (QuoteItem $record, array $data): ?Model {
                Log::debug('EditItemAction (Quote RelationManager): Iniciando atualização de item', [
                    'metodo'  => __METHOD__ . '@' . __LINE__,
                    'item_id' => $record->id,
                    'data'    => $data,
                ]);

                $service = new QuoteItemService();
                $item = $service->update($record, $data, Auth::id());

                if ($service->hasError()) {
                    notify::error(message: $service->getMessageUser(), errorCode: $service->getErrorCode());
                    return null;
                }

                notify::success(message: $service->getMessageUser());
                return $item;
            });
    }

    protected static function calculateValues(Get $get, Set $set): void
    {
        $quantity = self::parseMoneyValue($get('quantity'));
        $unitPrice = self::parseMoneyValue($get('unit_price'));
        $discountAmount = self::parseMoneyValue($get('discount_amount'));

        $subtotal = $quantity * $unitPrice;
        $set('subtotal', number_format($subtotal, 2, ',', '.'));

        $totalAmount = $subtotal - $discountAmount;
        $set('total_amount', number_format($totalAmount, 2, ',', '.'));
    }
}
