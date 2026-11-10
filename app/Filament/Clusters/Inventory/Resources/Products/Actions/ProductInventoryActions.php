<?php

namespace App\Filament\Clusters\Inventory\Resources\Products\Actions;

use App\Models\Product;
use App\Services\Access\UserAccessService;
use App\Services\Product\ProductInventoryService;
use App\Services\Product\ProductUnitConversionService;
use App\Services\StockMovement\Actions\PrintKardexPdfAction;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class ProductInventoryActions
{
    public static function make(Product $product): array
    {
        $allowed = fn (string $permission): bool => (int) $product->company_id === (int) Filament::getTenant()?->id
            && app(UserAccessService::class)->allows(Auth::user(), Filament::getTenant(), $permission);
        $refresh = function ($livewire): void {
            $livewire->dispatch('product-inventory-updated');
            Notification::make()->title('Estoque atualizado')->success()->send();
        };

        return [ActionGroup::make([
            Action::make('productMovement')->label('Registrar movimentação')
                ->visible(fn (): bool => $product->has_stock_control && $allowed('stock_movements.create'))
                ->modalHeading('Movimentar: '.$product->name)->schema([
                    Select::make('type')->label('Operação')->options(['entry' => 'Entrada', 'exit' => 'Saída', 'loss' => 'Perda'])->default('entry')->required()->live()
                        ->afterStateUpdated(fn (Get $get, Set $set) => self::suggestCost($product, $get, $set)),
                    Select::make('operational_unit')->label('Unidade')->options(fn (): array => array_combine(
                        $units = app(ProductUnitConversionService::class)->getAvailableUnits($product), $units
                    ))->default($product->unit->value)->required()->live()
                        ->afterStateUpdated(fn (Get $get, Set $set) => self::suggestCost($product, $get, $set)),
                    TextInput::make('quantity')->label('Quantidade')->numeric()->minValue(0.001)->step(0.001)->required()->live(onBlur: true),
                    TextEntry::make('conversion')->label('Quantidade no estoque')->state(function (Get $get) use ($product): string {
                        $conversion = app(ProductUnitConversionService::class)->convertToBase($product, $get('operational_unit') ?: $product->unit->value, (float) $get('quantity'));

                        return number_format($conversion->baseQuantity, 3, ',', '.').' '.$conversion->baseUnit.' ('.$conversion->displayRule.')';
                    }),
                    TextInput::make('unit_price')->label('Custo por unidade da operação (R$)')->numeric()->minValue(0)->step(0.0001)->required()
                        ->helperText('Na entrada, informe o custo de compra. Para saídas e perdas, use o custo da unidade movimentada.')
                        ->default(fn () => (float) ($product->stock()->value('last_cost') ?: $product->stock()->value('average_cost'))),
                    Textarea::make('reason')->label('Motivo')->required()->maxLength(500),
                ])->action(function (array $data, $livewire) use ($product, $refresh): void {
                    app(ProductInventoryService::class)->move($product, $data, Auth::user(), Filament::getTenant());
                    $refresh($livewire);
                }),
            Action::make('productCount')->label('Ajustar por contagem')
                ->visible(fn (): bool => $product->has_stock_control && $allowed('stock_movements.create'))
                ->schema([
                    TextInput::make('counted_quantity')->label('Quantidade contada ('.$product->unit->value.')')->numeric()->minValue(0)->step(0.001)->required()->live(onBlur: true),
                    TextEntry::make('count_preview')->label('Efeito da contagem')->state(function (Get $get) use ($product): string {
                        $stock = $product->stock()->first();
                        $count = (float) $get('counted_quantity');

                        return 'Saldo atual: '.$stock?->quantity_total.' | Ajuste: '.number_format($count - (float) $stock?->quantity_total, 3, ',', '.').
                            ' | Reservado: '.$stock?->quantity_reserved.' | Disponível após: '.number_format($count - (float) $stock?->quantity_reserved, 3, ',', '.');
                    }),
                    Textarea::make('reason')->label('Justificativa da contagem')->required()->maxLength(500),
                ])->action(function (array $data, $livewire) use ($product, $refresh): void {
                    app(ProductInventoryService::class)->move($product, $data, Auth::user(), Filament::getTenant(), count: true);
                    $refresh($livewire);
                }),
            Action::make('adjustProductCosts')->label('Ajustar custos')
                ->visible(fn (): bool => $product->has_stock_control && $allowed('products.update')
                    && app(ProductInventoryService::class)->canAdjustCosts(Auth::user(), Filament::getTenant()))
                ->schema([
                    TextInput::make('average_cost')->label('Custo médio (R$)')->numeric()->minValue(0)->step(0.0001)->required()
                        ->default(fn () => $product->stock()->value('average_cost')),
                    TextInput::make('last_cost')->label('Último custo (R$)')->numeric()->minValue(0)->step(0.0001)->required()
                        ->default(fn () => $product->stock()->value('last_cost')),
                    Textarea::make('reason')->label('Justificativa')->required()->maxLength(500),
                ])->action(function (array $data, $livewire) use ($product, $refresh): void {
                    app(ProductInventoryService::class)->adjustCosts($product, $data, Auth::user(), Filament::getTenant());
                    $refresh($livewire);
                }),
            Action::make('productKardex')->label('Kardex em PDF')
                ->visible(fn (): bool => $allowed('stock_movements.view'))
                ->schema([
                    DatePicker::make('start_date')->label('Data inicial'),
                    DatePicker::make('end_date')->label('Data final')->afterOrEqual('start_date'),
                ])->action(function (array $data) use ($product) {
                    app(ProductInventoryService::class)->authorize($product, Auth::user(), Filament::getTenant(), 'stock_movements.view');
                    $pdf = app(PrintKardexPdfAction::class)->execute($product, Filament::getTenant()->id, $data);
                    if (! $pdf) {
                        throw ValidationException::withMessages(['start_date' => 'Não foi possível gerar o Kardex.']);
                    }

                    return response()->streamDownload(fn () => print (base64_decode($pdf)), 'kardex-'.$product->id.'.pdf', ['Content-Type' => 'application/pdf']);
                }),
        ])->label('Estoque')->button()];
    }

    private static function suggestCost(Product $product, Get $get, Set $set): void
    {
        $stock = $product->stock()->first();
        $cost = $get('type') === 'entry' ? ((float) $stock?->last_cost ?: (float) $stock?->average_cost) : (float) $stock?->average_cost;
        $conversion = app(ProductUnitConversionService::class)->convertToBase($product, $get('operational_unit') ?: $product->unit->value, 1);
        $set('unit_price', round($cost * $conversion->factor, 4));
    }
}
