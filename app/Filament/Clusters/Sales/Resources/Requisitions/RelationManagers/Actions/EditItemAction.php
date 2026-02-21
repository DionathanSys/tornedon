<?php

namespace App\Filament\Clusters\Sales\Resources\Requisitions\RelationManagers\Actions;

use App\Models\RequisitionItem;
use App\Models\ServiceOrderItem;
use App\Services\ServiceOrderItem\ServiceOrderItemService;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use App\Notification\NotifyService as notify;
use App\Services\Product\ProductService;
use App\Services\RequisitionItem\RequisitionItemService;
use App\Services\Service\ServiceService;
use App\Traits\AuthorizesServiceOrderItemActions;
use App\Traits\ParsesMoneyValues;
use Filament\Actions\Action;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Facades\Log;
use Leandrocfe\FilamentPtbrFormFields\Money;

final class EditItemAction
{
    use AuthorizesServiceOrderItemActions;
    use ParsesMoneyValues;

    public static function make(): EditAction
    {
        return EditAction::make()
            ->label('Editar')
            ->visible(fn(RelationManager $livewire): bool => self::canModifyItems($livewire->getOwnerRecord()))
            ->schema([
                Select::make('product_id')
                    ->label('Peça')
                    ->searchable()
                    ->relationship('product', 'name', function ($query) {
                        $query->where('products.company_id', Filament::getTenant()->id);
                    })
                    ->required()
                    ->columnSpanFull()
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (Set $set, callable $get, $state) {
                        $product = (new ProductService())->find($state);
                        if ($product) {
                            $set('unit_price', number_format($product->sale_price_value, 2, ',', '.'));
                        } else {
                            $set('unit_price', null);
                        }
                        self::calculateValues($get, $set);
                    }),
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
                            ->afterStateUpdated(function($state, Set $set, Get $get) {
                                $set('discount_amount', number_format(0, 2, ',', '.'));
                                $set('discount_percentage', number_format(0, 2, ',', '.'));
                                self::calculateValues($get, $set);
                            }),
                        Money::make('unit_price')
                            ->label('Preço Unitário')
                            ->required()
                            ->live(onBlur: true)
                            ->afterStateUpdated(function($state, Set $set, Get $get) {
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
                            ->afterStateUpdated(function ($state, Set $set, Get $get) {
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
                            ->afterStateUpdated(function ($state, Set $set, Get $get) {
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
            ->using(function (RequisitionItem $record, array $data): ?Model {
                Log::debug('Iniciando atualização de item via RelationManager', [
                    'metodo' => __METHOD__ . '@' . __LINE__,
                    'item_id' => $record->id,
                    'data' => $data,
                ]);

                $service = new RequisitionItemService();
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

        // Calcula o subtotal
        $subtotal = $quantity * $unitPrice;
        $set('subtotal', number_format($subtotal, 2, ',', '.'));

        // Calcula o total
        $totalAmount = $subtotal - $discountAmount;
        $set('total_amount', number_format($totalAmount, 2, ',', '.'));
    }
}
