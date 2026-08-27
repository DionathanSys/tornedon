<?php

namespace App\Filament\Clusters\Financial\Resources\SefazDistributionDocuments\Actions;

use App\Models\SefazDistributionDocument;
use App\Services\Fiscal\Sefaz\SefazDistributionDanfeService;
use Filament\Actions\Action;
use Illuminate\Support\HtmlString;

class ViewDanfeAction
{
    public static function make(): Action
    {
        return Action::make('viewDanfe')
            ->label('Visualizar DANFE')
            ->icon('heroicon-o-document-magnifying-glass')
            ->modalHeading('DANFE da nota tomada')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Fechar')
            ->modalWidth('7xl')
            ->visible(fn (SefazDistributionDocument $record): bool => $record->full_xml_available)
            ->modalContent(function (SefazDistributionDocument $record): HtmlString {
                try {
                    $pdf = app(SefazDistributionDanfeService::class)->render($record);
                } catch (\RuntimeException $exception) {
                    return new HtmlString('<p class="text-danger-600">'.e($exception->getMessage()).'</p>');
                }

                return new HtmlString(
                    '<iframe src="data:application/pdf;base64,'.base64_encode($pdf).'" width="100%" height="700px" style="border:none;"></iframe>'
                );
            });
    }
}
