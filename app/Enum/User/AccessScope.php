<?php

namespace App\Enum\User;

enum AccessScope: string
{
    case NONE = 'none';
    case OWN = 'own';
    case ASSIGNED = 'assigned';
    case COMPANY = 'company';

    public static function options(): array
    {
        return ['none' => 'Não permitido', 'own' => 'Somente próprios', 'assigned' => 'Somente atribuídos', 'company' => 'Todos da empresa'];
    }
}
