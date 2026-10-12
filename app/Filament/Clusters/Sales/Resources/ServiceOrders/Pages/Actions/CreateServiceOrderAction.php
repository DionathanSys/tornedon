<?php

namespace App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions;

use App\Enum\ServiceOrder\Priority;
use App\Enum\ServiceOrder\State;
use App\Enum\ServiceOrder\Type;
use App\Filament\Clusters\Sales\Resources\Components\SelectPartner;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\Schemas\ServiceOrderForm;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\ServiceOrderResource;
use App\Models\Equipment;
use App\Notification\NotifyService as notify;
use App\Services\Equipment\EquipmentService;
use App\Services\ServiceOrder\ServiceOrderService;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

final class CreateServiceOrderAction
{
    public static function make(): CreateAction
    {
        return CreateAction::make()
            ->hiddenLabel()
            ->toolTip('Nova Ordem de Serviço')
            ->icon(Heroicon::Plus)
            ->modalWidth(Width::ExtraLarge)
            ->schema(fn (Schema $schema) => $schema->components([
                SelectPartner::make('customer_id', 'customer')
                    ->label('Cliente')
                    ->live()
                    ->afterStateUpdated(fn (Set $set) => $set('equipment_id', null))
                    ->columnSpanFull(),
                Select::make('equipment_id')->label('Veículo / equipamento')->searchable()->columnSpanFull()
                    ->disabled(fn (Get $get): bool => ! $get('customer_id'))
                    ->getSearchResultsUsing(fn (string $search, Get $get): array => app(EquipmentService::class)
                        ->searchForSelect($search, Filament::getTenant()->id, $get('customer_id'), 20, ['owner' => false]))
                    ->getOptionLabelUsing(fn ($value): ?string => Equipment::query()->where('company_id', Filament::getTenant()->id)->find($value)?->identifier)
                    ->rules(fn (Get $get): array => [Rule::exists('equipments', 'id')->where('company_id', Filament::getTenant()->id)->where('owner_id', $get('customer_id'))->whereNull('deleted_at')]),
                Section::make('Entrada do veículo e defeitos relatados')->columns(3)->columnSpanFull()
                    ->schema(ServiceOrderForm::operationalSheetFields()),
            ]))
            ->mutateDataUsing(function (array $data): array {
                $tenant = Filament::getTenant();

                $data['order_date'] = now();
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
            ->using(function (array $data, ?string $model, CreateAction $action): Model {
                $service = app(ServiceOrderService::class);
                $serviceOrder = $service->create($data, Auth::id());

                if ($service->hasError() || $serviceOrder === null) {
                    Log::error($service->getMessage(), [
                        'metodo' => __METHOD__.'@'.__LINE__,
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
                    'metodo' => __METHOD__.'@'.__LINE__,
                    'service_order_id' => $serviceOrder->id,
                ]);

                return $serviceOrder;
            })
            ->successRedirectUrl(fn ($record) => ServiceOrderResource::getUrl('edit', ['record' => $record]));
    }
}
