<?php

namespace App\Filament\Bpo\Resources\Invoices\RelationManagers;

use App\Filament\Clusters\Financial\Resources\Invoices\RelationManagers\RequisitionsRelationManager as AdminRelationManager;

class RequisitionsRelationManager extends AdminRelationManager
{
    use ReadOnlyInvoiceRelation;
}
