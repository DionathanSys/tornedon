<?php

namespace App\Filament\Bpo\Resources\Invoices\RelationManagers;

use App\Filament\RelationManagers\AttachmentsRelationManager as AdminRelationManager;
use Filament\Actions\Action;
use Filament\Tables\Table;

class AttachmentsRelationManager extends AdminRelationManager
{
    use ReadOnlyInvoiceRelation;

    public function table(Table $table): Table
    {
        $table = parent::table($table);
        $this->restrictInvoiceRelationActions($table, ['view', 'download']);
        $actions = array_filter($table->getRecordActions(), fn (Action $action): bool => in_array($action->getName(), ['view', 'download'], true));

        return $table->headerActions([])->recordActions(array_values($actions))->toolbarActions([]);
    }
}
