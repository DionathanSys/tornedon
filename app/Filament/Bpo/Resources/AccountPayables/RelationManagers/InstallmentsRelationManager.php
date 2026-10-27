<?php

namespace App\Filament\Bpo\Resources\AccountPayables\RelationManagers;

use App\Filament\Bpo\Resources\Concerns\HasCompactFinancialRelationship;
use Filament\Tables\Table;

class InstallmentsRelationManager extends \App\Filament\Clusters\Financial\Resources\AccountPayables\RelationManagers\InstallmentsRelationManager
{
    use HasCompactFinancialRelationship;

    protected static bool $isLazy = false;

    public function table(Table $table): Table
    {
        return $this->compactFinancialTable(parent::table($table), payments: false);
    }
}
