<?php

namespace App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions;

use App\Enum\ServiceOrder\Priority;
use App\Enum\ServiceOrder\State;
use App\Enum\ServiceOrder\Type;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\ServiceOrderResource;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\Schemas\ServiceOrderForm;
use App\Notification\NotifyService as notify;
use App\Services\ServiceOrder\ServiceOrderService;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

final class CreateServiceOrderAction
{
    public static function make(): CreateAction
    {
        return CreateAction::make()
            ->label('OS')
            ->color('gray')
            ->toolTip('Nova Ordem de Serviço')
            ->icon(Heroicon::Plus)
            ->size(Size::Small)
            ->mutateDataUsing(function (array $data): array {
                $tenant = Filament::getTenant();

                $data['company_id'] = $tenant->id;
                $data['status'] = State::OPEN;
                $data['priority'] = Priority::NORMAL;
                $data['type'] = Type::MAINTENANCE;

                unset($data['discount_amount']);
                $data['additional_info'] = ServiceOrderForm::normalizeAdditionalInfoState($data['additional_info'] ?? []);

                if (filled($data['customer_signature'] ?? null)) {
                    $data['customer_signed_at'] = now();
                } else {
                    $data['customer_signed_at'] = null;
                }

                return $data;
            })
            ->using(function (array $data, string $model, CreateAction $action): Model {
                $service = app(ServiceOrderService::class);
                $serviceOrder = $service->create($data, Auth::id());

                if ($service->hasError() || $serviceOrder === null) {
                    Log::error($service->getMessage(), [
                        'metodo' => __METHOD__ . '@' . __LINE__,
                        'message' => $service->getMessage(),
                        'error_code' => $service->getErrorCode(),
                        'errors' => $service->getErrors(),
                    ]);

                    notify::error(
                        message: $service->getMessageUser(),
                        errorCode: $service->getErrorCode()
                    );

                    $action->halt();
                }

                Log::info('CreateServiceOrder: Ordem de serviço criada com sucesso', [
                    'metodo' => __METHOD__ . '@' . __LINE__,
                    'service_order_id' => $serviceOrder->id,
                ]);

                return $serviceOrder;
            })
            ->successRedirectUrl(fn($record) => ServiceOrderResource::getUrl('edit', ['record' => $record]));
    }
}
