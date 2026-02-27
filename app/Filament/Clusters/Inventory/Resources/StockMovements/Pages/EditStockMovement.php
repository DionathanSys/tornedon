<?php

namespace App\Filament\Clusters\Inventory\Resources\StockMovements\Pages;

use App\Filament\Clusters\Inventory\Resources\StockMovements\Schemas\StockMovementForm;
use App\Filament\Clusters\Inventory\Resources\StockMovements\StockMovementResource;
use App\Services\StockMovement\StockMovementService;
use App\Models\StockMovement;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use App\Notification\NotifyService as notify;
use Illuminate\Database\Eloquent\Model;

class EditStockMovement extends EditRecord
{
    protected static string $resource = StockMovementResource::class;

    public function form(Schema $schema): Schema
    {
        return StockMovementForm::configure($schema);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        Log::debug('EditStockMovement: Mutando dados antes de atualizar', [
            'metodo'             => __METHOD__ . '@' . __LINE__,
            'stock_movement_id'  => $this->record->id,
            'data'               => $data,
        ]);

        $data['product_id'] = isset($data['product_stock_id']) 
            ? \App\Models\ProductStock::find($data['product_stock_id'])?->product_id 
            : $this->record->product_id;

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        Log::debug('EditStockMovement: Atualizando registro via service', [
            'metodo'             => __METHOD__ . '@' . __LINE__,
            'stock_movement_id'  => $record->id,
            'data'               => $data,
            'user_id'            => Auth::id(),
        ]);

        $service = app(StockMovementService::class);
        $updated = $service->update($record, $data, Auth::id());

        if ($service->hasError()) {
            Log::error('EditStockMovement: Erro ao atualizar movimentação', [
                'metodo'             => __METHOD__ . '@' . __LINE__,
                'stock_movement_id'  => $record->id,
                'error_code'         => $service->getErrorCode(),
                'message'            => $service->getMessage(),
                'errors'             => $service->getErrors(),
            ]);

            notify::error(
                message: $service->getMessageUser(),
                errorCode: $service->getErrorCode()
            );

            throw new \Exception($service->getMessage());
        }

        Log::info('EditStockMovement: Movimentação atualizada com sucesso', [
            'metodo'             => __METHOD__ . '@' . __LINE__,
            'stock_movement_id'  => $updated->id,
        ]);

        return $updated;
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->using(function (Model $record): bool {
                    Log::debug('EditStockMovement: Iniciando soft delete de movimentação', [
                        'metodo'             => __METHOD__ . '@' . __LINE__,
                        'stock_movement_id'  => $record->id,
                    ]);

                    $service = app(StockMovementService::class);
                    $result = $service->delete($record);

                    if ($service->hasError()) {
                        Log::error('EditStockMovement: Erro ao deletar movimentação', [
                            'metodo'             => __METHOD__ . '@' . __LINE__,
                            'stock_movement_id'  => $record->id,
                            'error_code'         => $service->getErrorCode(),
                            'message'            => $service->getMessage(),
                        ]);

                        notify::error(
                            message: $service->getMessageUser(),
                            errorCode: $service->getErrorCode()
                        );

                        return false;
                    }

                    Log::info('EditStockMovement: Movimentação excluída com sucesso', [
                        'metodo'             => __METHOD__ . '@' . __LINE__,
                        'stock_movement_id'  => $record->id,
                    ]);

                    return $result;
                }),
        ];
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Movimentação de estoque atualizada com sucesso';
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
