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
    case RESERVATION = 'reservation'; // Reserva de estoque (comprometido, não saiu fisicamente)
    case RESERVATION_RELEASE = 'reservation_release'; // Liberação de reserva

    public function label(): string
    {
        return match ($this) {
            self::ENTRY               => 'Entrada',
            self::EXIT                => 'Saída',
            self::ADJUSTMENT          => 'Ajuste',
            self::TRANSFER            => 'Transferência',
            self::RETURN              => 'Devolução',
            self::CONSUMPTION         => 'Consumo',
            self::LOSS                => 'Perda/Estrago',
            self::RESERVATION         => 'Reserva',
            self::RESERVATION_RELEASE => 'Liberação de Reserva',
        };
    }

    public function abbreviation(): string
    {
        return match ($this) {
            self::ENTRY               => 'ENT',
            self::EXIT                => 'SAI',
            self::ADJUSTMENT          => 'AJU',
            self::TRANSFER            => 'TRA',
            self::RETURN              => 'DEV',
            self::CONSUMPTION         => 'CON',
            self::LOSS                => 'PER',
            self::RESERVATION         => 'RES',
            self::RESERVATION_RELEASE => 'LIB',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::ENTRY               => 'success',
            self::EXIT                => 'danger',
            self::ADJUSTMENT          => 'info',
            self::TRANSFER            => 'warning',
            self::RETURN              => 'info',
            self::CONSUMPTION         => 'primary',
            self::LOSS                => 'danger',
            self::RESERVATION         => 'warning',
            self::RESERVATION_RELEASE => 'gray',
        };
    }

    /**
     * Retorna o sinal (+1 ou -1) que o tipo aplica à quantity_available.
     * Tipos de reserva NÃO afetam quantity_available (retornam 0).
     * ADJUSTMENT depende do sinal da quantidade informada.
     */
    public function quantitySign(): int
    {
        return match ($this) {
            self::ENTRY, self::RETURN                    => 1,
            self::ADJUSTMENT                             => 0, // sinal dependende da qty: use applyDelta()
            self::RESERVATION, self::RESERVATION_RELEASE => 0, // não altera quantity_available
            self::EXIT,
            self::TRANSFER,
            self::CONSUMPTION,
            self::LOSS                                   => -1,
        };
    }

    /**
     * Calcula o delta real a ser aplicado à quantity_available.
     * Reservas não mexem em quantity_available — retornam 0.
     */
    public function applyDelta(float $quantity): float
    {
        return match ($this) {
            self::ADJUSTMENT                             => $quantity, // quantidade já vem com sinal
            self::RESERVATION, self::RESERVATION_RELEASE => 0,        // tratado por applyReservationDelta
            default                                      => $this->quantitySign() * abs($quantity),
        };
    }

    /**
     * Calcula o delta a ser aplicado à quantity_reserved.
     * Apenas os tipos de reserva retornam delta != 0.
     */
    public function applyReservationDelta(float $quantity): float
    {
        return match ($this) {
            self::RESERVATION         =>  abs($quantity),  // aumenta reserva
            self::RESERVATION_RELEASE => -abs($quantity),  // diminui reserva
            default                   => 0,
        };
    }

    /**
     * Indica se este tipo afeta quantity_reserved (não quantity_available).
     */
    public function isReservationType(): bool
    {
        return match ($this) {
            self::RESERVATION, self::RESERVATION_RELEASE => true,
            default                                       => false,
        };
    }

    /**
     * Indica se este tipo de movimento aumenta o estoque (para efeitos de custo médio).
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
