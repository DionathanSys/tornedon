<?php

namespace App\Services\Quote\Support;

use App\Models\Quote;
use Illuminate\Support\Facades\Storage;

class QuotePdfDataFormatter
{
    /**
     * @return array<string, mixed>
     */
    public function format(Quote $quote): array
    {
        $items = $quote->items->sortBy('sequence')->values();

        return [
            'title' => '#'.$quote->quote_number.' - '.$quote->status?->description(),
            'header_lines' => [
                ['label' => 'Empresa', 'value' => $quote->company?->name ?? '-'],
                [
                    'label' => 'Cliente',
                    'value' => $quote->customer?->name ?? '-',
                    'secondary_value' => $quote->customer?->document_number,
                ],
                ['label' => 'Emitido em', 'value' => $quote->created_at?->format('d/m/Y') ?? '-'],
                ['label' => 'Válido até', 'value' => $quote->valid_until?->format('d/m/Y') ?? '-'],
                ['label' => 'Forma de pagamento', 'value' => $quote->payment_method?->description() ?? '-'],
                ['label' => 'Condição de pagamento', 'value' => $quote->payment_condition?->description() ?? '-'],
            ],
            'items' => $items->map(fn ($item): array => [
                'description' => $item->resolveDescription(),
                'unit_of_measure' => $item->unit_of_measure ?? '-',
                'quantity' => number_format((float) $item->quantity, 3, ',', '.'),
                'unit_price' => $this->formatMoney($item->unit_price),
                'discount_amount' => $this->formatMoney($item->discount_amount),
                'total_amount' => $this->formatMoney($item->total_amount),
            ])->all(),
            'description' => $quote->description,
            'customer_observations' => $quote->customer_observations,
            'summary_lines' => [
                ['label' => 'Subtotal', 'value' => $this->formatMoney($quote->gross_amount)],
                ['label' => 'Desconto', 'value' => $this->formatMoney($quote->discount_amount)],
                ['label' => 'Valor total', 'value' => $this->formatMoney($quote->total_amount)],
            ],
            'generated_at' => now()->format('d/m/Y H:i'),
            'company_logo' => $this->resolveCompanyLogo($quote),
            'company_name' => $quote->company?->name ?? '-',
        ];
    }

    private function formatMoney(float|int|null $value): string
    {
        return 'R$ '.number_format((float) $value, 2, ',', '.');
    }

    private function resolveCompanyLogo(Quote $quote): ?string
    {
        if (! filled($quote->company?->logo_path)) {
            return null;
        }

        $disk = Storage::disk(config('uploads.logo_disk'));

        if (! $disk->exists($quote->company->logo_path)) {
            return null;
        }

        $mime = $disk->mimeType($quote->company->logo_path) ?: 'image/png';

        return 'data:'.$mime.';base64,'.base64_encode((string) $disk->get($quote->company->logo_path));
    }
}
