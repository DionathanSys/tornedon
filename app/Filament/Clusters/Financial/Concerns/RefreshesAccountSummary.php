<?php

namespace App\Filament\Clusters\Financial\Concerns;

use Livewire\Attributes\On;

trait RefreshesAccountSummary
{
    #[On('refresh-installments')]
    #[On('refresh-payments')]
    public function refreshAccountSummary(): void
    {
        $this->getRecord()->refresh();
    }
}
