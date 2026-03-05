<?php

namespace App\Filament\Clusters\Sales\Resources\Quotes\RelationManagers\Actions;

use App\Enum\Quote\Destination;
use App\Filament\Clusters\Sales\Resources\Quotes\Schemas\Components\SchemaForm;
use App\Models\QuoteItem;
use App\Services\Product\ProductSalePriceService;
use App\Notification\NotifyService as notify;
use App\Services\QuoteItem\QuoteItemService;
use App\Traits\AuthorizesQuoteItemActions;
use App\Traits\ParsesMoneyValues;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

final class EditItemAction
{
    use AuthorizesQuoteItemActions;
    use ParsesMoneyValues;

    public static function make(): Action
    {
        return Action::make('editItem')
            ->label('Editar')
            ->icon(Heroicon::PencilSquare)
            ->iconButton()
            ->visible(fn(RelationManager $livewire): bool => self::canModifyQuoteItems($livewire->getOwnerRecord()))
            ->schema(SchemaForm::make('edit'))
            ->fillForm(function (array $data, QuoteItem $record) {
                $data['item'] = [
                    'real_product_id' => $record->product_id,
                    'real_service_id' => $record->service_id,
                    'code'            => $record->codeItem,
                    'name'            => $record->name,
                    'identification'  => $record->codeItem ? "[{$record->codeItem}] {$record->identifier}" : $record->identifier,
                    'min_sale_price'  => $record->product_id
                        ? (new ProductSalePriceService())->getMinSalePriceById($record->product_id)
                        : ($record->service->min_sale_price ?? 0),
                ];
                $data['description']            = $record->description;
                $data['unit_of_measure']        = $record->unit_of_measure;
                $data['destination']            = $record->destination->value;
                $data['quantity']               = $record->quantity;
                $data['unit_price']             = $record->unit_price;
                $data['discount_amount']        = $record->discount_amount;
                $data['discount_percentage']    = $record->discount_percentage;
                $data['total_amount']           = $record->total_amount;

                return $data;
            })
            ->action(function (QuoteItem $record, array $data, Action $action, RelationManager $livewire): ?Model {
                $quote = $livewire->getOwnerRecord();

                // Extração dos IDs do container 'item'
                $data['product_id']  = $data['item']['real_product_id'] ?? null;
                $data['service_id']  = $data['item']['real_service_id'] ?? null;

                // Parse numeric values from PT-BR format to float
                $data['quantity']            = self::parseMoneyValue($data['quantity'] ?? 0);
                $data['unit_price']          = self::parseMoneyValue($data['unit_price'] ?? 0);
                $data['discount_amount']     = self::parseMoneyValue($data['discount_amount'] ?? 0);
                $data['discount_percentage'] = self::parseMoneyValue($data['discount_percentage'] ?? 0);

                Log::debug('EditItemAction (Quote RelationManager): Iniciando atualização de item', [
                    'metodo'   => __METHOD__ . '@' . __LINE__,
                    'quote_id' => $quote->id,
                    'item_id'  => $record->id,
                    'data'     => $data,
                ]);

                $service = new QuoteItemService();
                $item = $service->update($record, $data, Auth::id());

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
