<?php

namespace App\Enum\Partner;

enum Type: string
{
    case SUPPLIER = 'supplier';
    case CUSTOMER = 'customer';
    case SALESPERSON = 'salesperson';
    case EMPLOYEE = 'employee';
    case CARRIER = 'carrier';
    case TECHNICIAN = 'technician';
    
    public function description(): string
    {
        return match ($this) {
            self::SUPPLIER      => 'Fornecedor',
            self::CUSTOMER      => 'Cliente',
            self::SALESPERSON   => 'Vendedor',
            self::EMPLOYEE      => 'Funcionário',
            self::CARRIER       => 'Transportadora',
            self::TECHNICIAN    => 'Técnico',
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
