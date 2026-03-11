<?php

namespace App\Filament\Clusters\Sales\Resources\Components;

use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Leandrocfe\FilamentPtbrFormFields\Money;

class DiscountAmountField
{
    /**
     * Retorna um campo Money para desconto com ação de aplicar integrada.
     *
     * @param string $modelType Tipo de modelo: 'service_order', 'requisition', 'production_order'
     * @return Money
     */
    public static function make(string $modelType = 'service_order'): Money
    {
        return Money::make('discount_amount')
            ->label('Desconto')
            ->saved(false)
            ->columnSpan(['md' => 2, 'lg' => 4])
            ->formatStateUsing(fn($state) => number_format($state ?? 0, 2, ',', '.'))
            ->default(0)
            ->suffixActions([
                Action::make('apply_discount')
                    ->label('Aplicar')
                    ->icon(Heroicon::CheckCircle)
                    ->color('success')
                    ->size('sm')
                    ->requiresConfirmation()
                    ->modalHeading('Aplicar Desconto')
                    ->modalDescription('Deseja aplicar este desconto igualmente entre todos os itens?')
                    ->modalSubmitActionLabel('Sim, aplicar')
                    ->modalCancelActionLabel('Cancelar')
                    ->action(function ($livewire) {
                        if (method_exists($livewire, 'applyDiscount')) {
                            $livewire->applyDiscount();
                        }
                    })->after(fn () => $this->dispatch('refresh-page')),
                Action::make('clear_discount')
                    ->label('Limpar')
                    ->icon(Heroicon::XCircle)
                    ->color('danger')
                    ->size('sm')
                    ->requiresConfirmation()
                    ->modalHeading('Remover Descontos')
                    ->modalDescription('Deseja remover todos os descontos dos itens?')
                    ->modalSubmitActionLabel('Sim, remover')
                    ->modalCancelActionLabel('Cancelar')
                    ->action(function ($livewire) {
                        if (method_exists($livewire, 'clearDiscount')) {
                            $livewire->clearDiscount();
                        }
                    })->after(fn () => $this->dispatch('refresh-page')),
            ]);
    }
}
