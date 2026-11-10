<?php

namespace App\Filament\Clusters\Inventory\Resources\Products\Pages;

use App\Filament\Clusters\Inventory\Resources\Products\Actions\ProductInventoryActions;
use App\Filament\Clusters\Inventory\Resources\Products\ProductResource;
use App\Notification\NotifyService as notify;
use App\Services\Product\ProductInventoryService;
use App\Services\Product\ProductService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    public function hasCombinedRelationManagerTabsWithContent(): bool
    {
        return true;
    }

    public function getContentTabLabel(): ?string
    {
        return 'Cadastro';
    }

    #[On('product-inventory-updated')]
    public function refreshInventory(): void
    {
        $this->record->refresh();
    }

    protected function afterSave(): void
    {
        $this->dispatch('product-inventory-updated');
    }

    protected function getHeaderActions(): array
    {
        return [
            ...ProductInventoryActions::make($this->record),
            ActionGroup::make([
                Action::make('new-product')
                    ->label('Produto')
                    ->url(ProductResource::getUrl('create'))
                    ->icon(Heroicon::Plus)
                    ->color('primary')
                    ->size(Size::Small),
                DeleteAction::make()
                    ->using(function (Model $record): bool {
                        $service = app(ProductService::class);
                        $result = $service->delete($record);
                        if ($service->hasError()) {
                            notify::error(
                                message: $service->getMessageUser(),
                                errorCode: $service->getErrorCode()
                            );

                            return false;
                        }

                        return $result;
                    })
                    ->size(Size::Small),
                ForceDeleteAction::make()
                    ->using(function (Model $record): bool {
                        $service = app(ProductService::class);
                        $result = $service->forceDelete($record);

                        if ($service->hasError()) {
                            notify::error(
                                message: $service->getMessageUser(),
                                errorCode: $service->getErrorCode()
                            );

                            return false;
                        }

                        return $result;
                    })->size(Size::Small),
                RestoreAction::make()
                    ->using(function (Model $record): bool {
                        $service = app(ProductService::class);
                        $result = $service->restore($record);

                        if ($service->hasError()) {
                            notify::error(
                                message: $service->getMessageUser(),
                                errorCode: $service->getErrorCode()
                            );

                            return false;
                        }

                        return $result;
                    })->size(Size::Small),
            ])->buttonGroup(),
        ];
    }

    protected function resolveRecord(int|string $key): Model
    {
        return static::getResource()::getEloquentQuery()->withTrashed()->findOrFail($key);
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['tax'] = $this->record->tax ? $this->record->tax->toArray() : null;
        $stock = $this->record->stock;
        $data['stock'] = [
            'allow_negative' => $stock?->allow_negative ?? false,
            'quantity_minimum' => $stock?->quantity_minimum ?? 0,
            'quantity_maximum' => $stock?->quantity_maximum,
        ];
        $data['alternative_unit_conversions'] = $this->record->alternativeUnitConversions
            ->map(fn ($conversion): array => [
                'unit' => $conversion->unit?->value ?? (string) $conversion->unit,
                'conversion_factor' => (float) $conversion->conversion_factor,
            ])
            ->values()
            ->all();

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {

        if (isset($data['tax']['ncm_code'])) {
            $data['tax']['ncm_code'] = str_replace('.', '', $data['tax']['ncm_code']);
        }

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return DB::transaction(function () use ($record, $data): Model {
            $inventory = app(ProductInventoryService::class);
            $inventory->authorize($record, Auth::user(), Filament::getTenant(), 'products.update');
            $stock = $record->stock()->lockForUpdate()->first();
            if (array_key_exists('has_stock_control', $data) && ! $data['has_stock_control'] && $stock
                && ((float) $stock->quantity_total !== 0.0 || (float) $stock->quantity_reserved !== 0.0)) {
                throw ValidationException::withMessages(['data.has_stock_control' => 'Zere o saldo e resolva as reservas antes de desativar o controle.']);
            }
            $fields = ['origin_sale_price', 'profit_margin', 'min_sale_price', 'sale_price_value', 'has_stock_control'];
            $before = $record->only($fields);
            $service = app(ProductService::class);
            $product = $service->update($record, $data, Auth::id());
            if ($service->hasError() || ! $product) {
                throw ValidationException::withMessages(['data.name' => $service->getMessageUser()]);
            }
            if ($product->has_stock_control && isset($data['stock'])) {
                $inventory->saveConfiguration($product, $data['stock'], Auth::user(), Filament::getTenant());
            }
            $inventory->record($product, 'product.pricing.updated', 'Preço e controle do produto atualizados', $before, $product->only($fields), Auth::user());

            return $product;
        });
    }
}
