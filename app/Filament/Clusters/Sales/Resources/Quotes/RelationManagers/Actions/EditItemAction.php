<?php

namespace App\Filament\Clusters\Sales\Resources\Quotes\RelationManagers\Actions;

use App\Filament\Clusters\Sales\Resources\Quotes\Schemas\Components\SchemaForm;
use App\Models\QuoteItem;
use App\Notification\NotifyService as notify;
use App\Services\QuoteItem\QuoteItemService;
use App\Traits\AuthorizesQuoteItemActions;
use App\Traits\ParsesMoneyValues;
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

    public static function make(): EditAction
    {
        return EditAction::make()
            ->label('Editar')
            ->visible(fn(RelationManager $livewire): bool => self::canModifyQuoteItems($livewire->getOwnerRecord()))
            ->schema(SchemaForm::make('edit'))
            ->action(function (QuoteItem $record, array $data, RelationManager $livewire): ?Model {
                $quote = $livewire->getOwnerRecord();

                // Extração dos IDs e Descrição do container 'item'
                $data['product_id']  = $data['item']['real_product_id'] ?? null;
                $data['service_id']  = $data['item']['real_service_id'] ?? null;
                $data['description'] = $data['item']['name'] ?? null;

                // Removemos o container de seleção
                unset($data['item']);

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
                    return null;
                }

                notify::success(message: $service->getMessageUser());
                return $item;
            });
    }
}