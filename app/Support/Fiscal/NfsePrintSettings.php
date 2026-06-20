<?php

namespace App\Support\Fiscal;

final class NfsePrintSettings
{
    public const PREFERENCE_KEY = 'print_settings.nfse';

    /**
     * @return array<string, string>
     */
    public static function fieldOptions(): array
    {
        return [
            'service_name' => 'Nome Serviço',
            'service_order_number' => 'Nro OS',
            'equipment_display' => 'Equipamento',
            'customer_observations' => 'Observação Cliente',
            'invoice_number' => 'Nro Fatura',
        ];
    }
}
