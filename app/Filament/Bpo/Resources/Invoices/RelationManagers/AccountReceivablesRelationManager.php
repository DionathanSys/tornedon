<?php

namespace App\Filament\Bpo\Resources\Invoices\RelationManagers;

use App\Filament\Clusters\Financial\Resources\Invoices\RelationManagers\AccountReceivablesRelationManager as AdminRelationManager;

class AccountReceivablesRelationManager extends AdminRelationManager
{
    use ReadOnlyInvoiceRelation;
}
