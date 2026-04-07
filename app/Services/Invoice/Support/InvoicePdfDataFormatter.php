<?php

namespace App\Services\Invoice\Support;

use App\Models\Invoice;
use Illuminate\Support\Facades\Storage;

class InvoicePdfDataFormatter
{
    /**
     * @return array<string, mixed>
     */
    public function format(Invoice $invoice): array
    {
        $headerLines = [
            ['label' => 'Empresa', 'value' => $invoice->company?->name ?? '-', 'class' => 'muted'],
            ['label' => 'Cliente', 'value' => $invoice->customer?->name ?? '-'],
        ];

        $paymentLines = collect([
            ['label' => 'Condição de pagamento', 'value' => $invoice->payment_condition?->description() ?? '-'],
            ['label' => 'Método de pagamento', 'value' => $invoice->payment_method?->description() ?? '-'],
        ])->values()->all();

        $summaryLines = collect([
            $invoice->discount_amount > 0
                ? ['label' => 'Desconto total', 'value' => $this->formatMoney($invoice->discount_amount)]
                : null,
            ['label' => 'Total geral da fatura', 'value' => $this->formatMoney($invoice->total_amount)],
        ])->filter()->values()->all();

        $fiscalDocuments = $invoice->fiscalDocuments->map(function ($fiscalDocument) {
            $number = $fiscalDocument->document_number
                ? '#' . $fiscalDocument->document_number
                : '-';

            $series = filled($fiscalDocument->document_series)
                ? (string) $fiscalDocument->document_series
                : '-';

            return [
                'number' => $number,
                'model' => $fiscalDocument->document_type?->description() ?? '-',
                'series' => $series,
                'status' => $fiscalDocument->status?->description() ?? '-',
                'issued_at' => $this->formatDate($fiscalDocument->issued_at),
            ];
        })->all();

        $serviceOrders = $invoice->serviceOrders->map(function ($serviceOrder) {
            return [
                'number' => '#' . $serviceOrder->number,
                'status' => $serviceOrder->status?->description() ?? '-',
                'items' => $serviceOrder->items->map(function ($item) {
                    return ($item->service?->name ?? '-') . ' (' . $this->formatQuantity($item->quantity) . ')';
                })->all(),
                'total' => $this->formatMoney($serviceOrder->total_amount),
            ];
        })->all();

        $requisitions = $invoice->requisitions->map(function ($requisition) {
            return [
                'number' => '#' . $requisition->number,
                'status' => $requisition->status?->description() ?? '-',
                'items' => $requisition->items->map(function ($item) {
                    return ($item->product?->name ?? '-') . ' (' . $this->formatQuantity($item->quantity) . ')';
                })->all(),
                'total' => $this->formatMoney($requisition->total_amount),
            ];
        })->all();

        $productionOrders = $invoice->productionOrders->map(function ($productionOrder) {
            $lineTotal = $productionOrder->items->sum(function ($item) {
                $qty = (float) ($item->quantity_approved ?: $item->quantity_produced ?: $item->quantity);
                $unit = (float) ($item->quoteItem?->unit_price ?? 0);

                return $qty * $unit;
            });

            return [
                'number' => '#' . $productionOrder->production_order_number,
                'status' => $productionOrder->status?->description() ?? '-',
                'items' => $productionOrder->items->map(function ($item) {
                    return ($item->product?->name ?? '-') . ' (' . $this->formatQuantity($item->quantity) . ')';
                })->all(),
                'total' => $this->formatMoney($lineTotal),
            ];
        })->all();

        return [
            'title' => 'Fatura #' . $invoice->invoice_number,
            'status' => $invoice->status?->description() ?? '-',
            'invoice_date' => $this->formatDate($invoice->invoice_date),
            'header_lines' => $headerLines,
            'payment_lines' => $paymentLines,
            'fiscal_documents' => $fiscalDocuments,
            'service_order_count' => $invoice->serviceOrders->count(),
            'requisition_count' => $invoice->requisitions->count(),
            'production_order_count' => $invoice->productionOrders->count(),
            'service_orders' => $serviceOrders,
            'requisitions' => $requisitions,
            'production_orders' => $productionOrders,
            'summary_lines' => $summaryLines,
            'generated_at' => now()->format('d/m/Y H:i'),
            'company_logo' => $this->resolveCompanyLogo($invoice),
            'company_name' => $invoice->company?->name ?? '-',
        ];
    }

    private function formatDate($date): string
    {
        return $date?->format('d/m/Y') ?? '-';
    }

    private function formatMoney($value): string
    {
        return 'R$ ' . number_format((float) $value, 2, ',', '.');
    }

    private function formatQuantity($value): string
    {
        return number_format((float) $value, 3, ',', '.');
    }

    private function resolveCompanyLogo(Invoice $invoice): ?string
    {
        if (! filled($invoice->company?->logo_path)) {
            return null;
        }

        $logoDisk = Storage::disk('public');

        if (! $logoDisk->exists($invoice->company->logo_path)) {
            return null;
        }

        $logoMime = $logoDisk->mimeType($invoice->company->logo_path) ?: 'image/png';

        return 'data:' . $logoMime . ';base64,' . base64_encode((string) $logoDisk->get($invoice->company->logo_path));
    }
}
