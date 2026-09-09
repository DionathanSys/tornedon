<?php

namespace App\Filament\Operation\Actions;

use App\Enum\Requisition\Status;
use App\Filament\Clusters\Sales\Resources\Components\SelectPartner;
use App\Filament\Operation\Pages\Requisitions\RequisitionDetail;
use App\Models\Requisition;
use App\Notification\NotifyService as notify;
use App\Services\Requisition\RequisitionService;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

final class CreateRequisitionAction
{
    public static function make(): CreateAction
    {
        return CreateAction::make('createRequisition')
            ->label('Nova Requisição')
            ->icon(Heroicon::Plus)
            ->schema(fn (Schema $schema): Schema => $schema->components([
                SelectPartner::make('customer_id', 'customer')
                    ->label('Cliente')
                    ->columnSpanFull(),
            ]))
            ->mutateDataUsing(function (array $data): array {
                $tenant = Filament::getTenant();

                $data['company_id'] = $tenant->getKey();
                $data['status'] = Status::OPEN;
                $data['sale_date'] = now();
                $data['delivery_date'] = now();

                return $data;
            })
            ->using(function (array $data, ?string $model, CreateAction $action): Model {
                $service = app(RequisitionService::class);
                $requisition = $service->create($data, (int) Auth::id());

                if ($service->hasError() || $requisition === null) {
                    Log::error($service->getMessage(), [
                        'metodo' => __METHOD__.'@'.__LINE__,
                        'message' => $service->getMessage(),
                        'error_code' => $service->getErrorCode(),
                        'errors' => $service->getErrors(),
                    ]);

                    notify::error(
                        message: $service->getMessageUser(),
                        errorCode: $service->getErrorCode(),
                    );

                    $action->halt();
                }

                Log::info('Operation: Requisição criada com sucesso', [
                    'metodo' => __METHOD__.'@'.__LINE__,
                    'requisition_id' => $requisition->id,
                ]);

                return $requisition;
            })
            ->successRedirectUrl(fn (Requisition $record): string => RequisitionDetail::getUrl(
                ['record' => $record],
                tenant: Filament::getTenant(),
            ));
    }
}
