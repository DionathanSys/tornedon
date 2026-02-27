<?php

namespace App\Filament\Clusters\Inventory\Resources\StockMovements\Pages;

use App\Filament\Clusters\Inventory\Resources\StockMovements\Schemas\StockMovementForm;
use App\Filament\Clusters\Inventory\Resources\StockMovements\StockMovementResource;
use App\Services\StockMovement\StockMovementService;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use App\Notification\NotifyService as notify;
use Illuminate\Database\Eloquent\Model;

class CreateStockMovement extends CreateRecord
{
    protected static string $resource = StockMovementResource::class;

    public function form(Schema $schema): Schema
    {
        return StockMovementForm::configure($schema);
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        Log::debug('CreateStockMovement: Mutando dados antes de criar', [
            'metodo' => __METHOD__ . '@' . __LINE__,
            'data' => $data,
        ]);

        $tenant = Filament::getTenant();
        $data['company_id'] = $tenant->id;
        $data['product_id'] = isset($data['product_stock_id']) 
            ? \App\Models\ProductStock::find($data['product_stock_id'])?->product_id 
            : null;

        return $data;
    }

    protected function handleRecordCreation(array $data): Model
    {
        Log::debug('CreateStockMovement: Criando registro via service', [
            'metodo'  => __METHOD__ . '@' . __LINE__,
            'data'    => $data,
            'user_id' => Auth::id(),
        ]);

        $service = app(StockMovementService::class);
        $movement = $service->create($data, Auth::id());

        if ($service->hasError()) {
            Log::error('CreateStockMovement: Erro ao criar movimentação', [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'error_code' => $service->getErrorCode(),
                'message'    => $service->getMessage(),
                'errors'     => $service->getErrors(),
            ]);

            notify::error(
                message: $service->getMessageUser(),
                errorCode: $service->getErrorCode()
            );

            throw new \Exception($service->getMessage());
        }

        Log::info('CreateStockMovement: Movimentação criada com sucesso', [
            'metodo'             => __METHOD__ . '@' . __LINE__,
            'stock_movement_id'  => $movement->id,
        ]);

        return $movement;
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Movimentação de estoque criada com sucesso';
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
