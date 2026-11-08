<?php

namespace App\Filament\Bpo\Resources\Invoices\RelationManagers;

use App\Filament\Clusters\Financial\Resources\Invoices\RelationManagers\FiscalDocumentsRelationManager as AdminRelationManager;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

class FiscalDocumentsRelationManager extends AdminRelationManager
{
    use ReadOnlyInvoiceRelation;

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return parent::canViewForRecord($ownerRecord, $pageClass) && Gate::allows('view', $ownerRecord);
    }

    public function table(Table $table): Table
    {
        // Invoice composition stays read-only; fiscal actions have their own permissions.
        return parent::table($table)->recordUrl(null)->headerActions([])->toolbarActions([]);
    }
}
