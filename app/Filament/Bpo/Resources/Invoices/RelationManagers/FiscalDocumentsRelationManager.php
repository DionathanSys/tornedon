<?php

namespace App\Filament\Bpo\Resources\Invoices\RelationManagers;

use App\Filament\Clusters\Financial\Resources\Invoices\RelationManagers\FiscalDocumentsRelationManager as AdminRelationManager;

class FiscalDocumentsRelationManager extends AdminRelationManager
{
    use ReadOnlyInvoiceRelation;
}
