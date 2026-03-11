<?php

namespace App\Filament\Clusters\Sales\Resources\Components;

use Filament\Actions\Action as ActionsAction;
use Filament\Forms\Components\Actions\Action;
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
            ->suffixAction(
                ActionsAction::make('apply_discount')
                    ->label('Aplicar')
                    ->icon('heroicon-m-check')
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
                    })
            );
    }
}
