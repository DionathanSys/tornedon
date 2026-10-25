<?php

namespace App\Filament\Bpo\Resources\BankStatementImports;

use App\Services\Access\CompanyAuthorization;
use Filament\Facades\Filament;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;

class LinesRelationManager extends \App\Filament\Clusters\Financial\Resources\BankStatementImports\RelationManagers\LinesRelationManager
{
    public function booted(): void
    {
        Gate::authorize('view', $this->getOwnerRecord());
    }

    public function table(Table $table): Table
    {
        $table = parent::table($table);
        foreach ([...$table->getRecordActions(), ...$table->getToolbarActions()] as $action) {
            $action->authorize(fn () => Gate::allows('update', $this->getOwnerRecord())
                && (! str_contains($action->getName(), 'reverse') || app(CompanyAuthorization::class)->allowsRecord(
                    auth()->user(), Filament::getTenant(), 'bank_statements.reverse', $this->getOwnerRecord())));
        }

        return $table;
    }
}
