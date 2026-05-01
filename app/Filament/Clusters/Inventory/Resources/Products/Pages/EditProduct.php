<?php

namespace App\Filament\Clusters\Inventory\Resources\Products\Pages;

use App\Filament\Clusters\Inventory\Resources\Products\ProductResource;
use App\Notification\NotifyService as notify;
use App\Services\Product\ProductService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
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
        return static::getModel()::withTrashed()->findOrFail($key);
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['tax'] = $this->record->tax ? $this->record->tax->toArray() : null;
        $data['alternative_unit_conversions'] = $this->record->alternativeUnitConversions
            ->map(fn($conversion): array => [
                'unit' => $conversion->unit?->value ?? (string) $conversion->unit,
                'conversion_factor' => (float) $conversion->conversion_factor,
            ])
            ->values()
            ->all();

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {

        $data['tax']['ncm_code'] = str_replace('.', '', $data['tax']['ncm_code']);

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $service = app(ProductService::class);
        $product = $service->update($record, $data, Auth::id());

        if ($service->hasError() || $product === null) {
            notify::error(
                message: $service->getMessageUser(),
                errorCode: $service->getErrorCode()
            );
            $this->halt();
        }

        return $product;
    }
}
