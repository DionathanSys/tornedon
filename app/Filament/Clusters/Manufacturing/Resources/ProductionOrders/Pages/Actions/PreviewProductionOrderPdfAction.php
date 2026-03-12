<?php

namespace App\Filament\Clusters\Manufacturing\Resources\ProductionOrders\Pages\Actions;

use App\Models\ProductionOrder;
use App\Services\ProductionOrder\ProductionOrderService;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;

final class PreviewProductionOrderPdfAction
{
    public static function make(): Action
    {
        return Action::make('previewProductionOrderPdf')
            ->label('Preview PDF')
            ->icon(Heroicon::Eye)
            ->color('gray')
            ->modalHeading('Preview da Ordem de Producao')
            ->modalContent(function (ProductionOrder $record): \Illuminate\Contracts\Support\Htmlable {
                $service = app(ProductionOrderService::class);
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
