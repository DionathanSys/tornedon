<?php

namespace App\Filament\Bpo\Resources\Invoices\RelationManagers;

use App\Filament\Clusters\Financial\Resources\Invoices\RelationManagers\InvoiceSourcesRelationManager as AdminRelationManager;

class InvoiceSourcesRelationManager extends AdminRelationManager
{
    use ReadOnlyInvoiceRelation;
}
