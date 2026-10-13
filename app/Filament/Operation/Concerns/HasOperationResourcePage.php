<?php

namespace App\Filament\Operation\Concerns;

trait HasOperationResourcePage
{
    use AuthorizesOperationModule;

    public function getLayout(): string
    {
        return 'components.operation.layout';
    }
}
