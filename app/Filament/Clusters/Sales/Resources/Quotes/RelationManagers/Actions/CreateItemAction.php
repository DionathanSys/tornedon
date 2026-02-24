<?php

namespace App\Filament\Clusters\Sales\Resources\Quotes\RelationManagers\Actions;

use App\Filament\Clusters\Inventory\Resources\Products\Tables\ProductsTable;
use App\Filament\Clusters\Sales\Resources\Components\SelectProduct;
use App\Filament\Clusters\Sales\Resources\Quotes\Schemas\Components\SchemaForm;
use App\Filament\Tables\ProductsStockTable;
use App\Filament\Tables\ProductTable;
use App\Filament\Tables\ServiceTable;
use App\Notification\NotifyService as notify;
use App\Services\Product\ProductSalePriceService;
use App\Services\Product\ProductService;
use App\Services\ProductStock\ProductStockService;
use App\Services\QuoteItem\QuoteItemService;
use App\Services\Service\ServiceService;
use App\Traits\AuthorizesQuoteItemActions;
use App\Traits\ParsesMoneyValues;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\ModalTableSelect;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Leandrocfe\FilamentPtbrFormFields\Money;

final class CreateItemAction
{
    use AuthorizesQuoteItemActions;
    use ParsesMoneyValues;

    public static function make(): CreateAction
    {
        return CreateAction::make()
            ->label('Item')
            ->icon(Heroicon::Plus)
            ->badge()
            ->visible(fn(RelationManager $livewire): bool => self::canModifyQuoteItems($livewire->getOwnerRecord()))
            ->schema(fn(Schema $schema) => $schema->components(SchemaForm::configure()))
            ->action(function (array $data, Action $action, RelationManager $livewire): ?Model {
                $quote = $livewire->getOwnerRecord();

                $data['quote_id'] = $quote->id;
                unset($data['product_stock_id']);

                Log::debug('CreateItemAction (Quote RelationManager): Iniciando criação de item', [
                    'metodo'   => __METHOD__ . '@' . __LINE__,
                    'quote_id' => $quote->id,
                    'data'     => $data,
                ]);

                $service = new QuoteItemService();
                $item = $service->create($data, Auth::id());

                if ($service->hasError()) {
                    notify::error(message: $service->getMessageUser(), errorCode: $service->getErrorCode());
                    $action->halt();
                }

                notify::success(message: $service->getMessageUser());
                return $item;
            })
            ->successNotification(null);
    }

    protected static function calculateValues(callable $get, Set $set): void
    {
        $quantity = self::parseMoneyValue($get('quantity'));
        $unitPrice = self::parseMoneyValue($get('unit_price'));
        $discountAmount = self::parseMoneyValue($get('discount_amount'));

        $subtotal = $quantity * $unitPrice;
        $set('subtotal', number_format($subtotal, 2, ',', '.'));

        $totalAmount = $subtotal - $discountAmount;
        $set('total_amount', number_format($totalAmount, 2, ',', '.'));

        Log::debug('CreateItemAction (Quote): Valores recalculados', [
            'metodo'          => __METHOD__ . '@' . __LINE__,
            'quantity'        => $quantity,
            'unit_price'      => $unitPrice,
            'discount_amount' => $discountAmount,
            'subtotal'        => $subtotal,
            'total_amount'    => $totalAmount,
        ]);
    }
}
