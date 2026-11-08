<?php

namespace App\Filament\Bpo\Resources\Invoices\RelationManagers;

use App\Filament\Clusters\Financial\Resources\Invoices\RelationManagers\ServiceOrdersRelationManager as AdminRelationManager;

class ServiceOrdersRelationManager extends AdminRelationManager
{
    use ReadOnlyInvoiceRelation;
}
