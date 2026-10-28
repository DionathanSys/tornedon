<?php

namespace App\Filament\Clusters\Hr\Resources\HrPeople\Pages;

use App\Filament\Clusters\Hr\Resources\HrPeople\HrPersonResource;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\URL;

class ManageHrPeople extends ListRecords
{
    protected static string $resource = HrPersonResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')->label('Novo funcionário/sócio')->icon('heroicon-o-plus')->visible(fn () => HrPersonResource::canCreate())->url(fn () => HrPersonResource::getUrl('create')),
            Action::make('export')->label('Exportar CSV')->visible(fn () => HrPersonResource::allowed('export'))
                ->url(fn () => URL::temporarySignedRoute('hr.export', now()->addMinutes(10), ['company' => Filament::getTenant()->id, 'module' => 'people']))->openUrlInNewTab(),
        ];
    }
}
