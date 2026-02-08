<?php

namespace App\Enum\Product;

enum Unit: string
{
    case UN = 'UN';
    case PC = 'PC';
    case KG = 'KG';
    case MT = 'MT';
    case LT = 'LT';
    case BD = 'BD';
    case TB = 'TB';
    case CX = 'CX';
    case PT = 'PT';
    case M2 = 'M2';
    case M3 = 'M3';
    
    public function description(): string
    {
        return match ($this) {
            self::UN => 'Unidade',
            self::PC => 'Peça',
            self::KG => 'Quilograma',
            self::MT => 'Metro',
            self::LT => 'Litro',
            self::BD => 'Balde',
            self::TB => 'Tambor',
            self::CX => 'Caixa',
            self::PT => 'Pacote',
            self::M2 => 'Metro Quadrado',
            self::M3 => 'Metro Cúbico',
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
