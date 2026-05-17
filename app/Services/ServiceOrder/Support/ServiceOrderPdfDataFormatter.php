<?php

namespace App\Services\ServiceOrder\Support;

use App\Models\ServiceOrder;
use Illuminate\Support\Facades\Storage;

class ServiceOrderPdfDataFormatter
{
    /**
     * @return array<string, mixed>
     */
    public function format(ServiceOrder $serviceOrder): array
    {
        $requisition = $serviceOrder->requisition;
        $itemsTotal = round((float) $serviceOrder->services_total_amount, 2);
        $travelValue = (float) $serviceOrder->travel_value;
        $productsTotal = round((float) $serviceOrder->requisition_total_amount, 2);
        $discountTotal = round(
            (float) $serviceOrder->discount_amount + (float) ($requisition?->discount_amount ?? 0),
            2
        );
        $grandTotal = round((float) $serviceOrder->grand_total_amount, 2);

        $additionalInfoLabels = [
            'accessories' => 'Acessorios entregues',
            'avaria' => 'Avaria identificada',
            'budget' => 'Orcamento alinhado',
            'cleaning' => 'Limpeza executada',
            'guidance' => 'Orientacoes ao cliente',
            'parts' => 'Pecas substituidas',
            'pending' => 'Pendencia encontrada',
            'test' => 'Teste realizado',
            'warranty' => 'Garantia informada',
            'other' => 'Outro',
        ];

        $headerLines = [
            ['label' => 'Empresa', 'value' => $serviceOrder->company?->name ?? '-', 'class' => 'muted'],
            ['label' => 'Cliente', 'value' => $serviceOrder->customer?->name ?? '-'],
        ];

        $responsibles = collect([
            ['label' => 'Equipamento', 'value' => $serviceOrder->equipment?->identifier],
            ['label' => 'Tecnico', 'value' => $serviceOrder->technician?->name],
            ['label' => 'Supervisor', 'value' => $serviceOrder->supervisor?->name],
            ['label' => 'Vendedor', 'value' => $serviceOrder->salesperson?->name],
        ])->filter(fn (array $field) => filled($field['value']))->values()->all();

        $equipmentLines = $this->buildEquipmentLines($serviceOrder->equipment);

        $summaryLines = collect([
            ['label' => 'Serviços', 'value' => $this->formatMoney($itemsTotal)],
            $productsTotal > 0 ? ['label' => 'Produtos', 'value' => $this->formatMoney($productsTotal)] : null,
            $travelValue > 0 ? ['label' => 'Deslocamento', 'value' => $this->formatMoney($travelValue)] : null,
            $discountTotal > 0
                ? ['label' => 'Desconto total', 'value' => $this->formatMoney($discountTotal)]
                : null,
            ['label' => 'Valor total', 'value' => $this->formatMoney($grandTotal)],
        ])->filter()->values()->all();

        $items = $serviceOrder->items->map(fn ($item) => [
            'service' => $item->service?->name ?? '-',
            'quantity' => $this->formatQuantity($item->quantity),
            'unit_price' => $this->formatMoney($item->unit_price),
            'discount_amount' => $this->formatMoney($item->discount_amount),
            'total_amount' => $this->formatMoney($item->total_amount),
            'observations' => $item->observations ?? '-',
        ])->all();

        $requisitionData = null;

        if ($requisition !== null) {
            $requisitionData = [
                'title' => 'Requisição #' . $requisition->number,
                'items' => $requisition->items->map(fn ($item) => [
                    'product' => $item->product?->name ?? '-',
                    'unit_of_measure' => $item->unit_of_measure ?? '-',
                    'quantity' => $this->formatQuantity($item->quantity),
                    'unit_price' => $this->formatMoney($item->unit_price),
                    'discount_amount' => $this->formatMoney($item->discount_amount),
                    'total_amount' => $this->formatMoney($item->total_amount),
                    'observations' => $item->observations ?? '-',
                ])->all(),
            ];
        }

        $additionalInfoText = $this->buildAdditionalInfoText($serviceOrder->additional_info ?? [], $additionalInfoLabels);

        return [
            'title' => '#' . $serviceOrder->number . ' - ' . $serviceOrder->status?->description(),
            // 'status' => $serviceOrder->status?->description() ?? '-',
            'order_date' => $this->formatDate($serviceOrder->order_date),
            'completion_date' => $this->formatDate($serviceOrder->completion_date),
            'header_lines' => $headerLines,
            'responsibles' => $responsibles,
            'equipment_lines' => $equipmentLines,
            'items' => $items,
            'requisition' => $requisitionData,
            'customer_observations' => $serviceOrder->customer_observations ?? null,
            'solution' => $serviceOrder->solution ?? null,
            'technician_observations' => $serviceOrder->technician_observations ?? null,
            'additional_info_text' => $additionalInfoText,
            'summary_lines' => $summaryLines,
            'customer_signature' => $serviceOrder->customer_signature,
            'customer_signed_at' => $serviceOrder->customer_signed_at?->format('d/m/Y H:i'),
            'generated_at' => now()->format('d/m/Y H:i'),
            'company_logo' => $this->resolveCompanyLogo($serviceOrder),
            'company_name' => $serviceOrder->company?->name ?? '-',
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

    /**
     * @param array<int|string, mixed> $additionalInfo
     * @param array<string, string> $labels
     */
    private function buildAdditionalInfoText(array $additionalInfo, array $labels): ?string
    {
        $normalized = collect($additionalInfo);

        if ($normalized->isEmpty()) {
            return null;
        }

        $first = $normalized->first();

        if (is_array($first) && array_key_exists('type', $first)) {
            $normalized = $normalized
                ->map(function ($item) use ($labels) {
                    if (! is_array($item)) {
                        return null;
                    }

                    $type = $item['type'] ?? null;
                    $label = filled($type)
                        ? ($labels[$type] ?? (string) $type)
                        : 'Outro';
                    $observation = $item['observation'] ?? null;

                    return [
                        'label' => $label,
                        'value' => filled($observation) ? $observation : null,
                    ];
                })
                ->filter();
        } else {
            $normalized = $normalized
                ->map(function ($value, $key) use ($labels) {
                    $label = $labels[(string) $key] ?? (string) $key;

                    return [
                        'label' => $label,
                        'value' => is_scalar($value) ? (string) $value : null,
                    ];
                })
                ->filter();
        }

        return $normalized
            ->map(function ($info) {
                if (! is_array($info)) {
                    return null;
                }

                $label = $info['label'] ?? null;
                $value = $info['value'] ?? null;

                if (filled($label) && filled($value)) {
                    return "{$label}: {$value}";
                }

                return $label ?: $value;
            })
            ->filter()
            ->implode(' | ');
    }

    private function resolveCompanyLogo(ServiceOrder $serviceOrder): ?string
    {
        if (! filled($serviceOrder->company?->logo_path)) {
            return null;
        }

        $logoDisk = Storage::disk('public');

        if (! $logoDisk->exists($serviceOrder->company->logo_path)) {
            return null;
        }

        $logoMime = $logoDisk->mimeType($serviceOrder->company->logo_path) ?: 'image/png';

        return 'data:' . $logoMime . ';base64,' . base64_encode((string) $logoDisk->get($serviceOrder->company->logo_path));
    }

    /**
     * @return array<int, array{label: string, value: string}>
     */
    private function buildEquipmentLines($equipment): array
    {
        if (! $equipment) {
            return [];
        }

        return collect([
            ['label' => 'Nome', 'value' => $equipment->identifier . ' - ' . $equipment->name],
        ])->filter(fn (array $field) => filled($field['value']))
            ->values()
            ->all();
    }
}
