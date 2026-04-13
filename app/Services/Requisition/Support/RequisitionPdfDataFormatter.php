<?php

namespace App\Services\Requisition\Support;

use App\Models\Requisition;
use Illuminate\Support\Facades\Storage;

class RequisitionPdfDataFormatter
{
    /**
     * @return array<string, mixed>
     */
    public function format(Requisition $requisition): array
    {
        $headerLines = [
            ['label' => 'Empresa', 'value' => $requisition->company?->name ?? '-', 'class' => 'muted'],
            ['label' => 'Cliente', 'value' => $requisition->customer?->name ?? '-'],
            ['label' => 'Forma de Pagamento', 'value' => $requisition->payment_method?->description() ?? '-'],
            ['label' => 'Condicao de Pagamento', 'value' => $requisition->payment_condition?->description() ?? '-'],
        ];

        $responsibles = collect([
            ['label' => 'OS vinculada', 'value' => $requisition->serviceOrder?->number],
            ['label' => 'Equipamento', 'value' => $requisition->equipment?->name],
            ['label' => 'Vendedor', 'value' => $requisition->salesperson?->name],
            ['label' => 'Data de Entrega', 'value' => $this->formatDate($requisition->delivery_date)],
            ['label' => 'Endereco de Entrega', 'value' => $requisition->delivery_address],
        ])->filter(fn (array $field) => filled($field['value']) && $field['value'] !== '-')
            ->values()
            ->all();

        $items = $requisition->items->map(fn ($item) => [
            'product' => $item->product?->name ?? '-',
            'unit_of_measure' => $item->unit_of_measure ?? '-',
            'quantity' => $this->formatQuantity($item->quantity),
            'unit_price' => $this->formatMoney($item->unit_price),
            'discount_amount' => $this->formatMoney($item->discount_amount),
            'total_amount' => $this->formatMoney($item->total_amount),
            'observations' => $item->observations ?? '-',
        ])->all();

        $summaryLines = collect([
            $requisition->discount_amount > 0
                ? ['label' => 'Desconto total', 'value' => $this->formatMoney($requisition->discount_amount)]
                : null,
            ['label' => 'Valor total', 'value' => $this->formatMoney($requisition->total_amount)],
        ])->filter()->values()->all();

        return [
            'title' => 'Requisicao #' . $requisition->number,
            'status' => $requisition->status?->description() ?? '-',
            'sale_date' => $this->formatDate($requisition->sale_date),
            'header_lines' => $headerLines,
            'responsibles' => $responsibles,
            'items' => $items,
            'observations' => $requisition->observations ?: null,
            'summary_lines' => $summaryLines,
            'generated_at' => now()->format('d/m/Y H:i'),
            'company_logo' => $this->resolveCompanyLogo($requisition),
            'company_name' => $requisition->company?->name ?? '-',
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

    private function resolveCompanyLogo(Requisition $requisition): ?string
    {
        if (! filled($requisition->company?->logo_path)) {
            return null;
        }

        $logoDisk = Storage::disk('public');

        if (! $logoDisk->exists($requisition->company->logo_path)) {
            return null;
        }

        $logoMime = $logoDisk->mimeType($requisition->company->logo_path) ?: 'image/png';

        return 'data:' . $logoMime . ';base64,' . base64_encode((string) $logoDisk->get($requisition->company->logo_path));
    }
}
