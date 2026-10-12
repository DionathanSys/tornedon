<?php

namespace App\Services\ServiceOrder;

use App\Models\ServiceOrder;
use App\Support\ServiceOrderOperationalSheet;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OperationalSheetPdfService
{
    public function download(ServiceOrder $order): StreamedResponse
    {
        abort_unless(ServiceOrderOperationalSheet::enabled($order->company_id), 403);
        $order->loadMissing(['company', 'customer', 'equipment', 'technician', 'defects']);
        $binary = Pdf::loadView('pdf.service-order-operational', ['record' => $order])->setPaper('a4')->output();
        $number = preg_replace('/[^a-zA-Z0-9_-]/', '-', (string) $order->number);

        return response()->streamDownload(function () use ($binary): void {
            echo $binary;
        }, 'ficha-operacional-'.$number.'.pdf', ['Content-Type' => 'application/pdf']);
    }
}
