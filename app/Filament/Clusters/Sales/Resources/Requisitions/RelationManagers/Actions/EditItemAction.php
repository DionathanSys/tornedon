<?php

namespace App\Filament\Clusters\Sales\Resources\Requisitions\RelationManagers\Actions;

use App\Filament\Clusters\Sales\Resources\Components\SelectProduct;
use App\Filament\Clusters\Sales\Resources\Requisitions\Schemas\ItemsForm;
use App\Models\RequisitionItem;
use App\Services\Product\ProductSalePriceService;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use App\Notification\NotifyService as notify;
use App\Services\RequisitionItem\RequisitionItemService;
use App\Traits\AuthorizesRequisitionItemActions;
use App\Traits\ParsesMoneyValues;
use Filament\Actions\Action;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Log;
use Leandrocfe\FilamentPtbrFormFields\Money;

final class EditItemAction
{
    use AuthorizesRequisitionItemActions;
    use ParsesMoneyValues;

    public static function make(): EditAction
    {
        return EditAction::make()
            ->label('Editar')
            ->visible(fn(RelationManager $livewire): bool => self::canModifyItems($livewire->getOwnerRecord()))
            ->mutateRecordDataUsing(function (array $data, RequisitionItem $record): array {
                $data['_min_sale_price'] = $record->product_id
                    ? (new ProductSalePriceService())->getMinSalePriceById($record->product_id)
                    : 0;
                return $data;
            })
            ->schema(fn(Schema $schema) => ItemsForm::configure($schema))
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
