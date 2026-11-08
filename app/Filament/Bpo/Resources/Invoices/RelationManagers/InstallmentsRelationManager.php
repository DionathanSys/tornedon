<?php

namespace App\Filament\Bpo\Resources\Invoices\RelationManagers;

use App\Filament\Clusters\Financial\Resources\Invoices\RelationManagers\InstallmentsRelationManager as AdminRelationManager;

class InstallmentsRelationManager extends AdminRelationManager
{
    use ReadOnlyInvoiceRelation;
}
