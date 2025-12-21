<?php

namespace App\Services\ServiceOrder;

use App\Domain\Exceptions\ServiceOrder\InvalidStateTransitionException;
use App\Models\ServiceOrder;
use App\Enum\ServiceOrder\State;

class StateResolver
{
    public static function resolve(ServiceOrder $ordem)//: ServiceOrderState
    {
        return match ($ordem->status) {
            State::OPEN    => new States\OpenState($ordem),
            default     => throw new InvalidStateTransitionException('Estado inválido'),
        };
    }
}


//TODO: Implementar Enum para os estados das ordens de serviço