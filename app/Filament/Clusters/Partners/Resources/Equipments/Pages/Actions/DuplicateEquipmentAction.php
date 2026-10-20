<?php

namespace App\Filament\Clusters\Partners\Resources\Equipments\Pages\Actions;

use App\Filament\Clusters\Partners\Resources\Equipments\Schemas\EquipmentForm;
use App\Models\Equipment;
use App\Notification\NotifyService as notify;
use App\Services\Equipment\EquipmentService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

final class DuplicateEquipmentAction
{
    public static function make(): Action
    {
        return Action::make('duplicateEquipment')
            ->label('Duplicar')
            ->icon(Heroicon::DocumentDuplicate)
            ->color('gray')
            ->tooltip('Duplicar equipamento')
            ->visible(function (Equipment $record, mixed $livewire): bool {
                $tenant = Filament::getTenant();

                return $tenant !== null
                    && (int) $record->company_id === (int) $tenant->getKey()
                    && ! $record->trashed()
                    && $livewire::getResource()::canCreate();
            })
            ->modalHeading('Duplicar Equipamento')
            ->modalDescription('Confira os dados da cópia. O número de série deve ser informado novamente, se necessário.')
            ->modalSubmitActionLabel('Duplicar')
            ->modalCancelActionLabel('Cancelar')
            ->modalWidth(Width::FourExtraLarge)
            ->schema(fn (Schema $schema): Schema => EquipmentForm::configure(
                $schema->model(Equipment::class)->operation('create'),
                forDuplication: true,
            ))
            ->fillForm(fn (Equipment $record): array => [
                'owner_id' => $record->owner_id,
                'name' => $record->name,
                'type' => $record->type?->value,
                'placa' => $record->placa,
                'mark' => $record->mark,
                'model' => $record->model,
                'serial_number' => null,
            ])
            ->action(function (Equipment $record, array $data, Action $action, mixed $livewire): void {
                $tenant = Filament::getTenant();

                abort_unless(
                    $tenant !== null
                    && (int) $record->company_id === (int) $tenant->getKey()
                    && ! $record->trashed()
                    && $livewire::getResource()::canCreate()
                    && Auth::check(),
                    403,
                );

                $data['company_id'] = $tenant->getKey();
                $data['created_by'] = Auth::id();

                $service = app(EquipmentService::class);
                $duplicated = $service->create($data);

                if ($duplicated === null) {
                    notify::error(message: 'Não foi possível duplicar o equipamento.');
                    $action->halt();
                }

                notify::success('Equipamento duplicado com sucesso.');
                redirect($livewire::getResource()::getUrl('edit', ['record' => $duplicated]));
            });
    }
}
