<?php

namespace App\Filament\Clusters\Inventory\Resources\Products\Pages;

use App\Filament\Clusters\Inventory\Resources\Products\ProductResource;
use App\Notification\NotifyService as notify;
use App\Services\Product\ProductService;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
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
                }),
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
                }),
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
                }),
        ];
    }

    protected function resolveRecord(int|string $key): Model
    {
        return static::getModel()::withTrashed()->findOrFail($key);
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['tax'] = $this->record->tax ? $this->record->tax->toArray() : null;

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['tax.ncm_code'] = str_replace(['.', '-'], '', $data['tax']['ncm_code'] ?? '');

        Log::debug('Dados do formulário antes de salvar o produto', [
            'metodo' => __METHOD__ . '@' . __LINE__,
            'data' => $data,
        ]);

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        Log::debug('Iniciando atualização de produto', [
            'metodo' => __METHOD__ . '@' . __LINE__,
            'product_id' => $record->id,
            'data' => $data,
        ]);
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
