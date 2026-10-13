<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\ManagementPanelProvider;
use App\Providers\Filament\MobilePanelProvider;
use App\Providers\Filament\OperationPanelProvider;

return [
    AppServiceProvider::class,
    AdminPanelProvider::class,
    ManagementPanelProvider::class,
    MobilePanelProvider::class,
    OperationPanelProvider::class,
];
