<?php

namespace App\Filament\Clusters\Sales\Resources\ServiceOrders\RelationManagers\Actions;

use App\Filament\Clusters\Sales\Resources\Services\Schemas\ServiceForm;
use App\Models\Service;
use App\Notification\NotifyService as notify;
use App\Services\Service\ServiceService;
use App\Traits\AuthorizesServiceOrderItemActions;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Enums\MaxWidth;
use Filament\Support\Enums\Size;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

final class CreateServiceAction
{
    use AuthorizesServiceOrderItemActions;

    public static function make(): Action
    {
        return Action::make('create-service')
            ->label('Novo serviço')
            ->icon(Heroicon::OutlinedWrenchScrewdriver)
            ->color('gray')
            ->size(Size::Small)
            ->visible(fn (RelationManager $livewire): bool => self::canModifyItems($livewire->getOwnerRecord()))
            ->modalHeading('Cadastrar serviço')
            ->modalSubmitActionLabel('Salvar serviço')
            ->modalWidth(Width::SevenExtraLarge)
            ->schema(fn (Schema $schema): Schema => ServiceForm::configure($schema))
            ->action(function (array $data): void {
                Log::debug('CreateServiceAction: Iniciando criação de serviço via modal do RelationManager', [
                    'metodo' => __METHOD__ . '@' . __LINE__,
                    'data' => $data,
                ]);

                $tenant = Filament::getTenant();
                $data['company_id'] = $tenant->id;

                $service = app(ServiceService::class);
                $serviceRecord = $service->create($data, Auth::id());

                if (($service->hasError()) || (! $serviceRecord instanceof Service)) {
                    Log::error('CreateServiceAction: Erro ao criar serviço via modal do RelationManager', [
                        'metodo' => __METHOD__ . '@' . __LINE__,
                        'error_code' => $service->getErrorCode(),
                        'message' => $service->getMessage(),
                        'errors' => $service->getErrors(),
                    ]);

                    notify::error(
                        message: $service->getMessageUser(),
                        errorCode: $service->getErrorCode(),
                    );

                    throw new \Filament\Support\Exceptions\Halt();
                }

                Log::info('CreateServiceAction: Serviço criado com sucesso via modal do RelationManager', [
                    'metodo' => __METHOD__ . '@' . __LINE__,
                    'service_id' => $serviceRecord->id,
                ]);

                notify::success(message: $service->getMessageUser());
            })
            ->successNotification(null);
    }
}
