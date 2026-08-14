<?php

namespace App\Filament\Clusters\Sales\Resources\Quotes\Pages\Actions;

use App\Models\Quote;
use App\Services\Quote\QuoteService;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

final class PreviewQuotePdfAction
{
    public static function make(): Action
    {
        return Action::make('previewQuotePdf')
            ->label('Preview PDF')
            ->icon(Heroicon::Eye)
            ->color('gray')
            ->modalHeading('Preview do Orçamento')
            ->modalContent(function (Quote $record): \Illuminate\Contracts\Support\Htmlable {
                $service = app(QuoteService::class);
                $data = $service->preview($record, Auth::id());

                if (! $data || ! ($data['pdf'] ?? null)) {
                    return new HtmlString('<p class="text-red-500">'.($service->getMessage() ?: 'Não foi possível gerar o preview.').'</p>');
                }

                $token = (string) Str::uuid();
                Cache::put('pdf_preview:'.$token, [
                    'pdf' => $data['pdf'],
                    'filename' => 'orcamento-'.($record->quote_number ?? $record->id).'.pdf',
                ], now()->addMinutes(5));

                $url = URL::temporarySignedRoute('pdf-preview.show', now()->addMinutes(5), ['token' => $token]);

                return new HtmlString('<iframe src="'.e($url).'" width="100%" height="600px" style="border:none;"></iframe>');
            })
            ->modalSubmitAction(false)
            ->modalWidth('6xl');
    }
}
