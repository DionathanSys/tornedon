<?php

namespace App\Enum\StockMovement;

enum Type: string
{
    case ENTRY = 'entry'; // Entrada de estoque
    case EXIT = 'exit'; // Saída de estoque
    case ADJUSTMENT = 'adjustment'; // Ajuste de estoque
    case TRANSFER = 'transfer'; // Transferência entre locais
    case RETURN = 'return'; // Devolução
    case CONSUMPTION = 'consumption'; // Consumo de produção
    case LOSS = 'loss'; // Perda/Estrago

    public function label(): string
    {
        return match ($this) {
            self::ENTRY       => 'Entrada',
            self::EXIT        => 'Saída',
            self::ADJUSTMENT  => 'Ajuste',
            self::TRANSFER    => 'Transferência',
            self::RETURN      => 'Devolução',
            self::CONSUMPTION => 'Consumo',
            self::LOSS        => 'Perda/Estrago',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::ENTRY       => 'success',
            self::EXIT        => 'danger',
            self::ADJUSTMENT  => 'info',
            self::TRANSFER    => 'warning',
            self::RETURN      => 'info',
            self::CONSUMPTION => 'primary',
            self::LOSS        => 'danger',
        };
    }

    /**
     * Retorna o sinal (+1 ou -1) que o tipo aplica à quantity_available.
     * ADJUSTMENT depende do sinal da quantidade informada.
     */
    public function quantitySign(): int
    {
        return match ($this) {
            self::ENTRY, self::RETURN    => 1,
            self::ADJUSTMENT             => 0, // sinal dependende da qty: use quantitySignFor()
            self::EXIT,
            self::TRANSFER,
            self::CONSUMPTION,
            self::LOSS                   => -1,
        };
    }

    /**
     * Calcula o delta real a ser aplicado à quantity_available.
     * Para ADJUSTMENT o delta é a própria quantidade (pode ser negativa).
     */
    public function applyDelta(float $quantity): float
    {
        return match ($this) {
            self::ADJUSTMENT => $quantity,           // quantidade já vem com sinal
            default          => $this->quantitySign() * abs($quantity),
        };
    }

    /**
     * Indica se este tipo de movimento aumenta o estoque (para efeitos de custo médio).
     * Em ADJUSTMENT considera-se entrada apenas quando qty > 0.
     */
    public function isInbound(): bool
    {
        return match ($this) {
            self::ENTRY, self::RETURN => true,
            default                   => false,
        };
    }

    /**
     * Indica se este tipo de movimento reduz o estoque.
     */
    public function isOutbound(): bool
    {
        return match ($this) {
            self::EXIT, self::CONSUMPTION, self::LOSS, self::TRANSFER => true,
            default                                                    => false,
        };
    }
}
