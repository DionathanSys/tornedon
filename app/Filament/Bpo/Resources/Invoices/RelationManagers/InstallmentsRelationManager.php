<?php

namespace App\Filament\Bpo\Resources\Invoices\RelationManagers;

use App\Filament\Clusters\Financial\Resources\Invoices\RelationManagers\InstallmentsRelationManager as AdminRelationManager;
use App\Models\AccountReceivableInstallment;
use App\Services\Access\CompanyAuthorization;
use Filament\Facades\Filament;
use Filament\Tables\Table;

class InstallmentsRelationManager extends AdminRelationManager
{
    use ReadOnlyInvoiceRelation;

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        $table = parent::table($table);
        $edit = $table->getAction('edit_installment');
        $this->restrictInvoiceRelationActions($table, ['edit_installment']);
        $edit->group(null)->authorize(fn (AccountReceivableInstallment $record): bool => (int) $record->company_id === (int) Filament::getTenant()?->id
            && $record->accountReceivable !== null
            && app(CompanyAuthorization::class)->allowsRecord(
                Filament::auth()->user(), Filament::getTenant(), 'account_receivables.update', $record->accountReceivable,
            ));

        return $table->recordUrl(null)->headerActions([])->recordActions([$edit])->toolbarActions([]);
    }
}
