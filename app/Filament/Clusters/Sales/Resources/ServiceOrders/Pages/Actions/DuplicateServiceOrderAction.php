<?php

namespace App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions;

use App\Enum\ServiceOrder\State;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\ServiceOrderResource;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderItem;
use App\Notification\NotifyService as notify;
use App\Services\ServiceOrder\ServiceOrderService;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class DuplicateServiceOrderAction
{
    public static function make(): Action
    {
        return Action::make('duplicateServiceOrder')
            ->label('Duplicar')
            ->icon(Heroicon::DocumentDuplicate)
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading('Duplicar Ordem de Serviço')
            ->modalDescription('Será criada uma nova OS com os mesmos dados e itens desta ordem.')
            ->modalSubmitActionLabel('Duplicar')
            ->action(function (ServiceOrder $record): void {
                Log::debug('DuplicateServiceOrderAction (Filament): Iniciando duplicação de OS', [
                    'metodo'           => __METHOD__ . '@' . __LINE__,
                    'service_order_id' => $record->id,
                    'user_id'          => Auth::id(),
                ]);

                $service = app(ServiceOrderService::class);

                try {
                    $newServiceOrder = DB::transaction(function () use ($record, $service): ?ServiceOrder {
                        $record->loadMissing('items');

                        $payload = [
                            'customer_id'             => $record->customer_id,
                            'company_id'              => $record->company_id,
                            'order_date'              => now()->toDateString(),
                            'scheduled_date'          => null,
                            'limit_date'              => null,
                            'completion_date'         => null,
                            'status'                  => State::OPEN->value,
                            'priority'                => $record->priority?->value ?? $record->priority,
                            'type'                    => $record->type?->value ?? $record->type,
                            'solution'                => $record->solution,
                            'equipment_id'            => $record->equipment_id,
                            'location'                => $record->location,
                            'customer_observations'   => $record->customer_observations,
                            'technician_observations' => $record->technician_observations,
                            'estimated_hours'         => $record->estimated_hours,
                            'actual_hours'            => null,
                            'travel_value'            => $record->travel_value,
                            'payment_method'          => $record->payment_method?->value ?? $record->payment_method,
                            'payment_condition'       => $record->payment_condition?->value ?? $record->payment_condition,
                            'technician_id'           => $record->technician_id,
                            'supervisor_id'           => $record->supervisor_id,
                            'salesperson_id'          => $record->salesperson_id,
                            'warranty_expires_at'     => $record->warranty_expires_at,
                            'requires_approval'       => $record->requires_approval,
                            'approved_by_customer'    => false,
                            'approved_at'             => null,
                            'customer_rating'         => null,
                            'customer_feedback'       => null,
                            'invoice_id'              => null,
                            'additional_info'         => $record->additional_info,
                        ];

                        $duplicated = $service->create($payload, Auth::id());

                        if ($service->hasError() || $duplicated === null) {
                            return null;
                        }

                        foreach ($record->items as $item) {
                            ServiceOrderItem::create([
                                'service_order_id'    => $duplicated->id,
                                'service_id'          => $item->service_id,
                                'quantity'            => $item->quantity,
                                'unit_price'          => $item->unit_price,
                                'unit_cost'           => $item->unit_cost,
                                'discount_percentage' => $item->discount_percentage,
                                'discount_amount'     => $item->discount_amount,
                                'observations'        => $item->observations,
                                'additional_info'     => $item->additional_info,
                                'created_by'          => Auth::id(),
                            ]);
                        }

                        return $duplicated;
                    });
                } catch (\Throwable $e) {
                    Log::error('DuplicateServiceOrderAction (Filament): Exceção ao duplicar OS', [
                        'metodo'           => __METHOD__ . '@' . __LINE__,
                        'service_order_id' => $record->id,
                        'error_message'    => $e->getMessage(),
                        'trace'            => $e->getTraceAsString(),
                    ]);

                    notify::error('Erro inesperado ao duplicar ordem de serviço.');

                    return;
                }

                if ($service->hasError() || ! isset($newServiceOrder) || $newServiceOrder === null) {
                    Log::error('DuplicateServiceOrderAction (Filament): Erro de serviço ao duplicar OS', [
                        'metodo'           => __METHOD__ . '@' . __LINE__,
                        'service_order_id' => $record->id,
                        'error_code'       => $service->getErrorCode(),
                        'message'          => $service->getMessage(),
                        'errors'           => $service->getErrors(),
                    ]);

                    notify::error(
                        message: $service->getMessageUser() ?: 'Não foi possível duplicar a ordem de serviço.',
                        errorCode: $service->getErrorCode()
                    );

                    return;
                }

                Log::info('DuplicateServiceOrderAction (Filament): OS duplicada com sucesso', [
                    'metodo'                => __METHOD__ . '@' . __LINE__,
                    'original_service_order' => $record->id,
                    'new_service_order'     => $newServiceOrder->id,
                ]);

                notify::success('Ordem de serviço duplicada com sucesso.');

                redirect(ServiceOrderResource::getUrl('edit', ['record' => $newServiceOrder]));
            });
    }
}
