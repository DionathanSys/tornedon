<?php

namespace App\Filament\Bpo\Resources\FiscalDocuments\RelationManagers;

use App\Filament\Clusters\Financial\Resources\FiscalDocuments\RelationManagers\ItemsRelationManager;
use Filament\Tables\Table;

class IncomingItemsRelationManager extends ItemsRelationManager
{
    use GuardsFiscalItems;

    public function table(Table $table): Table
    {
        return $this->guardItemsTable(parent::table($table));
    }
}
