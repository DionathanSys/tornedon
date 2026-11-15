<?php

namespace App\Services\StockMovement\Actions;

use App\Models\ProductStock;
use App\Models\StockMovement;
use Illuminate\Validation\ValidationException;

class ApplyMovementToProductStockAction
{
    /** Chamado dentro da transação, com o estoque bloqueado. */
    public function apply(ProductStock $stock, StockMovement $movement, bool $reverse = false): bool
    {
        $calculator = app(CalculateStockMovementStateAction::class);
        $before = $calculator->fromStock($stock);
        $after = $calculator->calculate($before, $movement, $reverse);

        if (! $stock->allow_negative) {
            if (! $movement->type->isReservationType() && ! $movement->isAdministrativeCostAdjustment()
                && $after['quantity_total'] < 0) {
                throw ValidationException::withMessages(['quantity' => 'Saldo físico insuficiente; o estoque não permite negativo.']);
            }
            if ($after['quantity_total'] < $before['quantity_total']
                && $after['quantity_reserved'] > $after['quantity_total'] + 0.0001) {
                throw ValidationException::withMessages(['quantity' => 'A saída não pode consumir o saldo reservado para outras operações.']);
            }
            if ($after['quantity_reserved'] > $before['quantity_reserved']
                && $after['quantity_reserved'] > $after['quantity_total'] + 0.0001) {
                throw ValidationException::withMessages(['quantity' => 'Saldo disponível insuficiente para reservar; o estoque não permite negativo.']);
            }
        }

        $stock->update($after);

        return true;
    }
}
