<?php

namespace App\Filament\Operation\Concerns;

use App\Filament\Operation\OperationModules;

trait AuthorizesOperationResource
{
    public static function canAccess(): bool
    {
        return OperationModules::allows(OperationModules::forPage(static::class) ?? 'home')
            && parent::canAccess();
    }
}
