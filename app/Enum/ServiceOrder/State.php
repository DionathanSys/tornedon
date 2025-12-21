<?php

namespace App\Enum\ServiceOrder;

enum State: string
{
    case OPEN = 'aberta';
    case CLOSED = 'encerrada';
    case INVOICED = 'faturada';
    case CANCELLED = 'cancelada';
}