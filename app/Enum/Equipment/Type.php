<?php

namespace App\Enum\Equipment;

enum Type: string
{
    case TRUCK = 'truck';
    case CAR = 'car';
    case GENERAL_ELECTRONIC = 'general_electronic';
    case OTHER = 'other';

    public function description(): string
    {
        return match ($this) {
            self::TRUCK => 'Caminhão',
            self::CAR => 'Carro',
            self::GENERAL_ELECTRONIC => 'Eletrônico em Geral',
            self::OTHER => 'Outro',
        };
    }

    public static function toSelectArray(): array
    {
        $options = [];

        foreach (self::cases() as $item) {
            /** @var self $item */
            $options[$item->value] = $item->description();
        }

        return $options;
    }
}
