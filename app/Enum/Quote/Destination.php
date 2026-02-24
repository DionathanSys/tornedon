<?php

namespace App\Enum\Quote;

enum Destination: string
{
    case ORDER_PRODUCTION = 'order_production';
    case ORDER_SERVICE = 'order_service';
    case REQUISITION = 'requisition';

    public function description(): string
    {
        return match ($this) {
            self::ORDER_PRODUCTION => 'Ordem de Produção',
            self::ORDER_SERVICE => 'Ordem de Serviço',
            self::REQUISITION => 'Requisição',
        };
    }

    public static function toSelectArray(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->description()])
            ->toArray();
    }
}