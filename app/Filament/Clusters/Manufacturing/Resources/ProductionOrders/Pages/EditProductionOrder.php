<?php

namespace App\Filament\Clusters\Manufacturing\Resources\ProductionOrders\Pages;

use App\Filament\Clusters\Manufacturing\Resources\ProductionOrders\Pages\Actions\InvoiceProductionOrderAction;
use App\Filament\Clusters\Manufacturing\Resources\ProductionOrders\ProductionOrderResource;
use App\Notification\NotifyService as notify;
use App\Services\ProductionOrder\ProductionOrderService;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class EditProductionOrder extends EditRecord
{
    protected static string $resource = ProductionOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            InvoiceProductionOrderAction::make(),
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['updated_by'] = Auth::id();
        
        return $data;
    }

    /**
     * Aplica o desconto igualmente entre os itens da OP.
     */
    public function applyDiscount(): void
    {
        $record = $this->record;
        $discountAmount = (float) str_replace(['.', ','], ['', '.'], $this->data['discount_amount'] ?? '0');

        if ($discountAmount <= 0) {
            notify::warning(
                message: 'Informe um valor de desconto maior que zero.',
                errorCode: 'DISCOUNT_INVALID'
            );
            return;
        }

        $service = app(ProductionOrderService::class);
        $result = $service->applyDiscount($record, $discountAmount);

        if (! $result) {
            Log::error('EditProductionOrder: Erro ao aplicar desconto', [
                'metodo'               => __METHOD__ . '@' . __LINE__,
                'message'              => $service->getMessage(),
                'production_order_id'  => $record->id,
            ]);

            notify::error(
                message: $service->getMessageUser(),
                errorCode: $service->getErrorCode()
            );
            return;
        }

        Log::info('EditProductionOrder: Desconto aplicado com sucesso', [
            'metodo'               => __METHOD__ . '@' . __LINE__,
            'production_order_id'  => $record->id,
            'discount_amount'      => $discountAmount,
        ]);

        notify::success(
            message: 'Desconto aplicado com sucesso aos itens.'
        );

        // Recarrega os itens para refletir as mudanças
        $this->record->refresh();
        $this->fillForm();
    }

    protected function getRedirectUrl(): ?string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
