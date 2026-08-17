<?php

namespace App\Filament\Clusters\Financial\Resources\BankStatementLines\Pages;

use App\Filament\Clusters\Financial\Resources\BankStatementLines\BankStatementLineResource;
use Filament\Resources\Pages\ListRecords;
use Livewire\Attributes\On;

class ListBankStatementLines extends ListRecords
{
    protected static string $resource = BankStatementLineResource::class;

    #[On('refresh-statement-lines')]
    public function refreshStatementLines(): void
    {
        $this->resetTable();
    }
}
