<?php

namespace App\Enum\Partner;

enum Type: string
{
    case SUPPLIER = 'supplier';
    case CUSTOMER = 'customer';
    
    public function description(): string
    {
        return match ($this) {
            self::SUPPLIER => 'Fornecedor',
            self::CUSTOMER => 'Cliente',
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
