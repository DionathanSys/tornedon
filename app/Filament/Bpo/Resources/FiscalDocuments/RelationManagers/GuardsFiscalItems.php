<?php

namespace App\Filament\Bpo\Resources\FiscalDocuments\RelationManagers;

use App\Enum\FiscalDocument\OperationType;
use App\Filament\Bpo\Concerns\SecuresBpoActions;
use App\Filament\Bpo\Resources\FiscalDocuments\Pages\EditOutgoingDocument;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;

trait GuardsFiscalItems
{
    use SecuresBpoActions;

    public function booted(): void
    {
        $this->bpoActionPermissions = null;
        abort_unless((int) $this->getOwnerRecord()->company_id === (int) Filament::getTenant()->id, 403);
        Gate::authorize('view', $this->getOwnerRecord());
    }

    private function itemsEditable(): bool
    {
        $record = $this->getOwnerRecord();

        return $record->operation_type === OperationType::SAIDA ? EditOutgoingDocument::editable($record)
            : ! $record->confirmed && ! $record->canceled && ! $record->isImportedFromDfe() && ! $record->isOperationalHistory();
    }

    protected function guardItemsTable(Table $table): Table
    {
        $allowed = $this->itemsEditable() && $this->canUseBpoAction('fiscal_documents.update', $this->getOwnerRecord());
        foreach ([...$table->getRecordActions(), ...$table->getToolbarActions(), ...$table->getHeaderActions()] as $item) {
            foreach ($item instanceof ActionGroup ? $item->getFlatActions() : [$item] as $action) {
                $guard = function (): void {
                    $this->getOwnerRecord()->refresh();
                    $this->authorizeBpoAction('fiscal_documents.update', $this->getOwnerRecord());
                    abort_unless($this->itemsEditable(), 403);
                };
                $action->authorize($allowed)->beforeFormFilled($guard)->before($guard);
            }
        }

        return $table->modifyQueryUsing(fn ($query) => $query->with(['product', 'service', 'remittanceAsset.equipment']))->defaultPaginationPageOption(10)->paginated([10, 25, 50]);
    }
}
