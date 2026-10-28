<?php

namespace App\Filament\Clusters\Hr\Resources\HrEntries\Pages;

use App\Filament\Clusters\Hr\Resources\HrEntries\HrEntryResource;
use App\Services\Hr\HrService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Support\Facades\URL;

class ManageHrEntries extends ManageRecords
{
    protected static string $resource = HrEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->using(fn (array $data) => app(HrService::class)->saveEntry(auth()->user(), Filament::getTenant(), $data)),
            Action::make('export')->label('Exportar CSV')->visible(fn () => HrEntryResource::allowed('export'))
                ->url(fn () => URL::temporarySignedRoute('hr.export', now()->addMinutes(10), ['company' => Filament::getTenant()->id, 'module' => 'entries']))->openUrlInNewTab(),
        ];
    }
}
