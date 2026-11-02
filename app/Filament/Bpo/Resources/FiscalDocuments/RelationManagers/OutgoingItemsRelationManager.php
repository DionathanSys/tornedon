<?php

namespace App\Filament\Bpo\Resources\FiscalDocuments\RelationManagers;

use App\Filament\Clusters\Sales\Resources\FiscalDocuments\RelationManagers\ItemsRelationManager;
use Filament\Tables\Table;

class OutgoingItemsRelationManager extends ItemsRelationManager
{
    use GuardsFiscalItems;

    public function table(Table $table): Table
    {
        return $this->guardItemsTable(parent::table($table));
    }
}
