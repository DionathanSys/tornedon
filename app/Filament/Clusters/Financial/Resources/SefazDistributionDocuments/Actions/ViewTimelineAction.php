<?php

namespace App\Filament\Clusters\Financial\Resources\SefazDistributionDocuments\Actions;

use App\Filament\Clusters\Financial\Resources\SefazDistributionDocuments\SefazDistributionDocumentResource;
use App\Models\SefazDistributionDocument;
use Filament\Actions\Action;
use Filament\Facades\Filament;

class ViewTimelineAction
{
    public static function make(): Action
    {
        return Action::make('viewTimeline')
            ->label('Acompanhar')
            ->icon('heroicon-o-clock')
            ->url(fn(SefazDistributionDocument $record): string => SefazDistributionDocumentResource::getUrl('view', [
                'record' => $record,
                'tenant' => Filament::getTenant(),
            ]));
    }
}
