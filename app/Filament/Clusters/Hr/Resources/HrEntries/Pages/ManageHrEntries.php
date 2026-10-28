<?php

namespace App\Filament\Clusters\Hr\Resources\HrEntries\Pages;

use App\Filament\Clusters\Hr\Resources\HrEntries\HrEntryResource;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\URL;

class ManageHrEntries extends ListRecords
{
    protected static string $resource = HrEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')->label('Novo lançamento')->icon('heroicon-o-plus')->visible(fn () => HrEntryResource::canCreate())->url(fn () => HrEntryResource::getUrl('create')),
            Action::make('export')->label('Exportar CSV')->visible(fn () => HrEntryResource::allowed('export'))
                ->url(fn () => URL::temporarySignedRoute('hr.export', now()->addMinutes(10), ['company' => Filament::getTenant()->id, 'module' => 'entries']))->openUrlInNewTab(),
        ];
    }
}
