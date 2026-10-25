<?php

namespace App\Services\Access;

class PanelAccess
{
    public static function options(): array
    {
        return ['admin' => 'Gestão completa (Admin)', 'operation' => 'Operação', 'mobile' => 'Mobile', 'bpo' => 'BPO — área de trabalho desktop'];
    }
}
