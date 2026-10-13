<?php

namespace App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions;

use App\Models\ServiceOrder;
use App\Services\ServiceOrder\ServiceOrderService;
use Filament\Actions\Action;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
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
            ->modalSubmitAction(false)
            ->schema([
                Toggle::make('include_service_photos')
                    ->label('Incluir fotos do serviço no PDF')
                    ->helperText('Inclui somente imagens dos anexos do tipo Foto do Serviço ao final do documento.')
                    ->default(false)
                    ->live(),
                Text::make(function (ServiceOrder $record, Get $get): Htmlable {
                    $service = app(ServiceOrderService::class);
                    $data = $service->preview($record, Auth::id(), (bool) $get('include_service_photos'));

                    if (! $data || ! ($data['pdf'] ?? null)) {
                        return new HtmlString(
                            '<p class="text-red-500">'.($service->getMessage() ?: 'Não foi possível gerar o preview.').'</p>'
                        );
                    }

                    $token = (string) Str::uuid();

                    Cache::put('pdf_preview:'.$token, [
                        'pdf' => $data['pdf'],
                        'filename' => 'ordem-servico-'.($record->number ?? $record->id).'.pdf',
                    ], now()->addMinutes(5));

                    $previewUrl = URL::temporarySignedRoute('pdf-preview.show', now()->addMinutes(5), [
                        'token' => $token,
                    ]);

                    return new HtmlString(
                        '<iframe src="'.e($previewUrl).'" width="100%" height="600px" style="border:none;"></iframe>'
                    );
                })->columnSpanFull(),
            ])
            ->modalWidth('6xl');
    }
}
