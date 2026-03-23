<?php

namespace App\Filament\Clusters\Financial\Resources\Invoices\Pages\Actions;

use App\Models\Invoice;
use App\Services\Invoice\InvoiceService;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

final class PreviewInvoicePdfAction
{
    public static function make(): Action
    {
        return Action::make('previewInvoicePdf')
            ->label('Preview PDF')
            ->icon(Heroicon::Eye)
            ->color('gray')
            ->modalHeading('Preview da Fatura')
            ->modalContent(function (Invoice $record): \Illuminate\Contracts\Support\Htmlable {
                $service = app(InvoiceService::class);
                $data    = $service->preview($record, Auth::id());

                if (! $data || ! ($data['pdf'] ?? null)) {
                    return new HtmlString(
                        '<p class="text-red-500">' . ($service->getMessage() ?: 'Nao foi possivel gerar o preview.') . '</p>'
                    );
                }

                $token = (string) Str::uuid();

                Cache::put('pdf_preview:' . $token, [
                    'pdf' => $data['pdf'],
                    'filename' => 'fatura-' . ($record->invoice_number ?? $record->id) . '.pdf',
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
