<?php

namespace App\Filament\Clusters\Hr\Resources\HrPeople\Pages;

use App\Filament\Clusters\Hr\Resources\HrPeople\HrPersonResource;
use App\Services\Hr\HrService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Support\Facades\URL;

class ManageHrPeople extends ManageRecords
{
    protected static string $resource = HrPersonResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->using(fn (array $data) => app(HrService::class)->savePerson(auth()->user(), Filament::getTenant(), $data)),
            Action::make('export')->label('Exportar CSV')->visible(fn () => HrPersonResource::allowed('export'))
                ->url(fn () => URL::temporarySignedRoute('hr.export', now()->addMinutes(10), ['company' => Filament::getTenant()->id, 'module' => 'people']))->openUrlInNewTab(),
        ];
    }
}
