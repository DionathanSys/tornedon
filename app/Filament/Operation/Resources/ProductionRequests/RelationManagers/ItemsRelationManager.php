<?php

namespace App\Filament\Operation\Resources\ProductionRequests\RelationManagers;

use App\Filament\Operation\Concerns\AuthorizesOperationModule;

class ItemsRelationManager extends \App\Filament\Clusters\Sales\Resources\ProductionRequests\RelationManagers\ItemsRelationManager
{
    use AuthorizesOperationModule;
}
