<?php

namespace App\Filament\Operation;

use App\Filament\Operation\Concerns\AuthorizesOperationModule;
use Filament\Pages\Page;

abstract class OperationPage extends Page
{
    use AuthorizesOperationModule;

    protected static string $layout = 'components.operation.layout';
}
