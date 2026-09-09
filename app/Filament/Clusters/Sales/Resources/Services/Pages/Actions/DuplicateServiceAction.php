<?php

namespace App\Filament\Clusters\Sales\Resources\Services\Pages\Actions;

use App\Filament\Clusters\Sales\Resources\Services\ServiceResource;
use App\Models\Service;
use App\Notification\NotifyService as notify;
use App\Services\Service\ServiceService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

final class DuplicateServiceAction
{
    public static function make(): Action
    {
        return Action::make('duplicateService')
            ->label('Duplicar')
            ->icon(Heroicon::DocumentDuplicate)
            ->color('gray')
            ->tooltip('Duplicar serviço')
            ->requiresConfirmation()
            ->modalHeading('Duplicar Serviço')
            ->modalDescription('Será criado um novo serviço com os mesmos dados do registro atual.')
            ->modalSubmitActionLabel('Duplicar')
            ->modalCancelActionLabel('Cancelar')
            ->schema([
                TextInput::make('name')
                    ->label('Novo nome')
                    ->required()
                    ->maxLength(255)
                    ->default(fn (Service $record): string => $record->name.' (Cópia)'),
            ])
            ->action(function (Service $record, array $data, mixed $livewire): void {
                $tenant = Filament::getTenant();

                if ($tenant === null || (int) $record->company_id !== (int) $tenant->getKey()) {
                    notify::error(message: 'Não foi possível duplicar este serviço.');

                    return;
                }

                $userId = Auth::id();

                if ($userId === null) {
                    notify::error(message: 'Não foi possível identificar o usuário atual.');

                    return;
                }

                Log::debug('DuplicateServiceAction: iniciando duplicação de serviço', [
                    'service_id' => $record->id,
                    'company_id' => $record->company_id,
                    'user_id' => $userId,
                ]);

                $service = app(ServiceService::class);

                try {
                    $duplicated = $service->create(
                        static::makePayload($record, $data['name']),
                        (int) $userId,
                    );
                } catch (\Throwable $exception) {
                    Log::error('DuplicateServiceAction: exceção ao duplicar serviço', [
                        'service_id' => $record->id,
                        'company_id' => $record->company_id,
                        'user_id' => $userId,
                        'exception' => $exception->getMessage(),
                    ]);

                    notify::error(message: 'Erro inesperado ao duplicar serviço.');

                    return;
                }

                if ($service->hasError() || $duplicated === null) {
                    Log::error('DuplicateServiceAction: erro ao duplicar serviço', [
                        'service_id' => $record->id,
                        'company_id' => $record->company_id,
                        'user_id' => $userId,
                        'error_code' => $service->getErrorCode(),
                        'message' => $service->getMessage(),
                        'errors' => $service->getErrors(),
                    ]);

                    notify::error(
                        message: $service->getMessageUser() ?: 'Não foi possível duplicar o serviço.',
                        errorCode: $service->getErrorCode(),
                    );

                    return;
                }

                Log::info('DuplicateServiceAction: serviço duplicado com sucesso', [
                    'service_id' => $record->id,
                    'duplicated_service_id' => $duplicated->id,
                    'company_id' => $duplicated->company_id,
                    'user_id' => $userId,
                ]);

                notify::success('Serviço duplicado com sucesso.');

                redirect(static::resolveResource($livewire)::getUrl('edit', ['record' => $duplicated]));
            });
    }

    private static function makePayload(Service $record, string $name): array
    {
        $excludedAttributes = [
            'name',
            'service_code',
            'created_by',
            'updated_by',
            'company_id',
        ];

        $payload = [];

        foreach ($record->getFillable() as $attribute) {
            if (in_array($attribute, $excludedAttributes, true)) {
                continue;
            }

            $value = $record->getAttribute($attribute);

            if ($value instanceof BackedEnum) {
                $value = $value->value;
            }

            $payload[$attribute] = $value;
        }

        $payload['name'] = $name;
        $payload['company_id'] = $record->company_id;

        return $payload;
    }

    private static function resolveResource(mixed $livewire): string
    {
        if (is_object($livewire) && method_exists($livewire, 'getResource')) {
            return $livewire->getResource();
        }

        return ServiceResource::class;
    }
}
