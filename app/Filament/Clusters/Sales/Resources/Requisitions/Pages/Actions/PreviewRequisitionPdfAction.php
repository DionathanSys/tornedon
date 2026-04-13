<?php

namespace App\Filament\Clusters\Sales\Resources\Requisitions\Pages\Actions;

use App\Models\Requisition;
use App\Services\Requisition\RequisitionService;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;

final class PreviewRequisitionPdfAction
{
    public static function make(): Action
    {
        return Action::make('previewRequisitionPdf')
            ->label('Preview PDF')
            ->icon(Heroicon::Eye)
            ->color('gray')
            ->modalHeading('Preview da Requisição')
            ->modalContent(function (Requisition $record): \Illuminate\Contracts\Support\Htmlable {
                $service = app(RequisitionService::class);
                $data    = $service->preview($record, Auth::id());

                if (! $data || ! ($data['pdf'] ?? null)) {
                    return new HtmlString(
                        '<p class="text-red-500">' . ($service->getMessage() ?: 'Nao foi possivel gerar o preview.') . '</p>'
                    );
                }

                return new HtmlString(
                    '<iframe src="data:application/pdf;base64,' . $data['pdf'] . '" width="100%" height="600px" style="border:none;"></iframe>'
                );
            })
            ->modalWidth('6xl');
    }
}
