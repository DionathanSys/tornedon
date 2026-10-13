<?php

namespace App\Filament\Operation\Concerns;

use App\Filament\Operation\OperationModules;

trait AuthorizesOperationModule
{
    public function bootAuthorizesOperationModule(): void
    {
        $module = OperationModules::forPage(static::class);
        abort_unless(OperationModules::allows($module ?? 'home'), 403);
    }
}
