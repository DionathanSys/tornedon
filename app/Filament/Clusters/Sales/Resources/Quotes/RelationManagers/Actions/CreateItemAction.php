<?php

namespace App\Filament\Clusters\Sales\Resources\Quotes\RelationManagers\Actions;

use App\Enum\Quote\Destination;
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
            ->schema(SchemaForm::make('create'))
            ->action(function (array $data, Action $action, RelationManager $livewire): ?Model {
                $quote = $livewire->getOwnerRecord();

                // Extração dos IDs do container 'item'
                $data['product_id']  = $data['item']['real_product_id'] ?? null;
                $data['service_id']  = $data['item']['real_service_id'] ?? null;

                // Removemos o container de seleção para não interferir no registro
                unset($data['item']);

                $data['quote_id'] = $quote->id;
                
                // Parse numeric values from PT-BR format to float
                $data['quantity']            = self::parseMoneyValue($data['quantity'] ?? 0);
                $data['unit_price']          = self::parseMoneyValue($data['unit_price'] ?? 0);
                $data['discount_amount']     = self::parseMoneyValue($data['discount_amount'] ?? 0);
                $data['discount_percentage'] = self::parseMoneyValue($data['discount_percentage'] ?? 0);

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

    
}
