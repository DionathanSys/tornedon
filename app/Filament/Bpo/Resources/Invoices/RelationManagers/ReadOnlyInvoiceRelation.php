<?php

namespace App\Filament\Bpo\Resources\Invoices\RelationManagers;

use App\Models\Invoice;
use Filament\Facades\Filament;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

trait ReadOnlyInvoiceRelation
{
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Invoice
            && (int) $ownerRecord->company_id === (int) Filament::getTenant()?->id
            && Gate::allows('view', $ownerRecord);
    }

    public function booted(): void
    {
        abort_unless(static::canViewForRecord($this->getOwnerRecord(), $this->getPageClass()), 403);
    }

    public function table(Table $table): Table
    {
        $table = parent::table($table);
        $this->restrictInvoiceRelationActions($table);

        return $table
            ->recordUrl(null)
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }

    protected function restrictInvoiceRelationActions(Table $table, array $allowed = []): void
    {
        foreach ($table->getFlatActions() as $action) {
            if (! in_array($action->getName(), $allowed, true)) {
                $action->authorize(false);
            }
        }
    }
}
