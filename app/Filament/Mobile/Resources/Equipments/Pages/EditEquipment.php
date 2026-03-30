<?php

namespace App\Filament\Mobile\Resources\Equipments\Pages;

use App\Filament\Mobile\Resources\Equipments\EquipmentResource;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Size;

class EditEquipment extends EditRecord
{
    protected static string $resource = EquipmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                Action::make('index')
                    ->label('Voltar')
                    ->icon('heroicon-o-arrow-left')
                    ->url(EquipmentResource::getUrl())
                    ->size(Size::ExtraSmall),
                DeleteAction::make()
                    ->size(Size::ExtraSmall),
                RestoreAction::make()
                    ->size(Size::ExtraSmall),
            ])->buttonGroup(),
        ];
    }
}
