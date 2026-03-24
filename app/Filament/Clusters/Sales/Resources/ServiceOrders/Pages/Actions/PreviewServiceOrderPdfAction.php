<?php

namespace App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions;

use App\Models\ServiceOrder;
use App\Services\ServiceOrder\ServiceOrderService;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

final class PreviewServiceOrderPdfAction
{
    public static function make(): Action
    {
        return Action::make('previewServiceOrderPdf')
            ->label('Preview PDF')
            ->icon(Heroicon::Eye)
            ->color('gray')
            ->modalHeading('Preview da Ordem de Serviço')
            ->modalContent(function (ServiceOrder $record): \Illuminate\Contracts\Support\Htmlable {
                $service = app(ServiceOrderService::class);
                $data    = $service->preview($record, Auth::id());

                if (! $data || ! ($data['pdf'] ?? null)) {
                    return new HtmlString(
                        '<p class="text-red-500">' . ($service->getMessage() ?: 'Nao foi possivel gerar o preview.') . '</p>'
                    );
                }

                $token = (string) Str::uuid();

                Cache::put('pdf_preview:' . $token, [
                    'pdf' => $data['pdf'],
                    'filename' => 'ordem-servico-' . ($record->number ?? $record->id) . '.pdf',
                ], now()->addMinutes(5));

                $previewUrl = URL::temporarySignedRoute('pdf-preview.show', now()->addMinutes(5), [
                    'token' => $token,
                ]);

                return new HtmlString(
                    '<iframe src="' . e($previewUrl) . '" width="100%" height="600px" style="border:none;"></iframe>'
                );
            })
            ->modalWidth('6xl');
    }
}
