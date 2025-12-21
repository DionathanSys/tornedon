<?php

namespace App\Models;

use App\Services\ServiceOrder\StateResolver;
use App\Services\ServiceOrder\States\ServiceOrderState;
use Illuminate\Database\Eloquent\Model;

class ServiceOrder extends Model
{
    public function state(): ServiceOrderState
    {
        return StateResolver::resolve($this);
    }
}
