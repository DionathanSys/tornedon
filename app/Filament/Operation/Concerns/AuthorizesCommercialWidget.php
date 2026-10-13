<?php

namespace App\Filament\Operation\Concerns;

use App\Filament\Operation\OperationModules;

trait AuthorizesCommercialWidget
{
    public static function canView(): bool
    {
        return OperationModules::allows('commercial_dashboard');
    }

    public function bootAuthorizesCommercialWidget(): void
    {
        abort_unless(static::canView(), 403);
    }
}
