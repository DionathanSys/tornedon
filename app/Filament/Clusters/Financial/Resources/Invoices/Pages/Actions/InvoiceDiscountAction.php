<?php

namespace App\Filament\Clusters\Financial\Resources\Invoices\Pages\Actions;

use App\Enum\Invoice\InvoiceAction;
use App\Models\Invoice;
use App\Services\Invoice\InvoiceActionEligibilityService;
use App\Services\Invoice\InvoiceDiscountService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Validation\ValidationException;

class InvoiceDiscountAction
{
    public static function make(): Action
    {
        return Action::make('invoiceDiscount')
            ->label('Desconto de fechamento')->icon('heroicon-o-receipt-percent')
            ->visible(fn (Invoice $record): bool => app(InvoiceActionEligibilityService::class)->evaluate($record, InvoiceAction::EDIT_COMPOSITION)['allowed'])
            ->modalWidth('7xl')
            ->modalDescription('Distribua o desconto entre os itens. A participação representa a parcela do desconto total atribuída ao item. Deslocamento não participa. Para remover o desconto, informe zero.')
            ->fillForm(function (Invoice $record): array {
                $service = app(InvoiceDiscountService::class);
                $rows = $service->rows($record);
                $weights = array_map(fn (array $row): int => max(0, $row['base']), $rows);
                $shares = $service->allocate(array_sum($weights) > 0 ? 100000000 : 0, $weights);

                return [
                    'mode' => 'amount', 'value' => (float) $record->invoice_discount_amount,
                    'reason' => $record->invoice_discount_reason,
                    'signature' => $service->signature($rows),
                    'requires_review' => $record->invoice_discount_signature && $record->invoice_discount_signature !== $service->signature($rows),
                    'items' => array_values(array_map(fn (array $row): array => [
                        'key' => $row['key'], 'label' => $row['label'], 'base' => $row['base'],
                        'gross' => $row['gross'], 'sale_discount' => $row['sale_discount'],
                        'share' => $record->invoice_discount_signature ? $row['share'] : $shares[$row['key']] / 1000000,
                    ], $rows)),
                ];
            })
            ->schema([
                Hidden::make('signature'),
                Hidden::make('requires_review'),
                Text::make('A composição mudou desde o último rateio. Revise a distribuição e aplique novamente antes de confirmar a fatura.')
                    ->visible(fn (Get $get): bool => (bool) $get('requires_review')),
                Select::make('mode')->label('Informar desconto em')->options(['amount' => 'Reais', 'percentage' => 'Percentual da base dos itens'])->required()->live(),
                TextInput::make('value')->label('Desconto total')->numeric()->minValue(0)->required()->live(debounce: 400),
                Textarea::make('reason')->label('Motivo')->maxLength(255),
                Actions::make([
                    Action::make('proportional')->label('Ratear proporcionalmente')->action(function (Get $get, Set $set): void {
                        $items = $get('items') ?? [];
                        $weights = array_map(fn (array $item): int => max(0, (int) $item['base']), $items);
                        $shares = app(InvoiceDiscountService::class)->allocate(array_sum($weights) > 0 ? 100000000 : 0, $weights);
                        foreach ($items as $key => &$item) {
                            $item['share'] = $shares[$key] / 1000000;
                        }
                        $set('items', $items);
                    }),
                ]),
                Repeater::make('items')->label('Distribuição por item')->addable(false)->deletable(false)->reorderable(false)
                    ->columns(4)->schema([
                        Hidden::make('key'), Hidden::make('base'), Hidden::make('gross'), Hidden::make('sale_discount'), Hidden::make('label'),
                        Text::make(fn (Get $get): string => $get('label').' | Bruto: '.self::money($get('gross')).' | Desc. venda: '.self::money($get('sale_discount')).' | Base: '.self::money($get('base')))->columnSpan(2),
                        TextInput::make('share')->label('Participação no desconto (%)')->numeric()->step(0.000001)->minValue(0)->maxValue(100)->required()->live(debounce: 400),
                        Text::make(function (Get $get): string {
                            $amount = self::amount($get('../../mode'), $get('../../value'), $get('../../items') ?? []);
                            $distribution = self::previewDistribution($amount, $get('../../items') ?? []);
                            $discount = $distribution[$get('key')] ?? 0;

                            return 'Desc. fatura: '.self::money($discount).' | Líquido: '.self::money((int) $get('base') - $discount);
                        }),
                    ]),
                Text::make(function (Get $get): string {
                    $items = $get('items') ?? [];
                    $amount = self::amount($get('mode'), $get('value'), $items);
                    $share = array_sum(array_column($items, 'share'));
                    $distributed = array_sum(self::previewDistribution($amount, $items));
                    $difference = $amount - $distributed;

                    return 'Desconto definido: '.self::money($amount).' | Distribuído: '.self::money($distributed).' | Participação: '.number_format($share, 6, ',', '.').'% | '.($difference < 0 ? 'Excedente: ' : 'Pendente: ').self::money(abs($difference));
                }),
            ])
            ->action(function (Invoice $record, array $data, $livewire, Action $action): void {
                abort_unless((int) $record->company_id === (int) Filament::getTenant()->id, 403);
                // Re-read the actual bases: browser-provided item values are never trusted.
                $service = app(InvoiceDiscountService::class);
                $rows = $service->rows($record);
                $amount = self::amount($data['mode'], $data['value'], $rows);
                try {
                    $service->apply($record, $amount / 100, array_column($data['items'], 'share', 'key'), $data['reason'] ?? null, Filament::auth()->id(), $data['signature'] ?? '');
                } catch (ValidationException $exception) {
                    Notification::make()->danger()->title('Revise o desconto de fechamento')
                        ->body(collect($exception->errors())->flatten()->implode(' '))->send();
                    $action->halt();
                }
                Notification::make()->success()->title('Desconto de fechamento atualizado')->send();
                $livewire->dispatch('invoice-fiscal-documents-refresh');
            });
    }

    private static function amount(?string $mode, mixed $value, array $items): int
    {
        return (int) round($mode === 'percentage' ? array_sum(array_column($items, 'base')) * (float) $value / 100 : (float) $value * 100);
    }

    private static function previewDistribution(int $amount, array $items): array
    {
        $shares = array_map(fn (array $item): float => max(0, (float) ($item['share'] ?? 0)), $items);
        $weights = array_combine(array_column($items, 'key'), array_values($shares)) ?: [];
        if ($amount >= 0 && (int) round(array_sum($shares) * 1000000) === 100000000) {
            return app(InvoiceDiscountService::class)->allocate($amount, $weights);
        }

        return array_map(fn (float $share): int => (int) round($amount * $share / 100), $weights);
    }

    private static function money(mixed $cents): string
    {
        return 'R$ '.number_format((float) $cents / 100, 2, ',', '.');
    }
}
