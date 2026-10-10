<?php

namespace App\Livewire;

use Filament\Facades\Filament;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Livewire\Component;

class OperationMenu extends Component
{
    public bool $showTenantModal = false;

    public int|string|null $tenantId = null;

    public function openTenantModal(): void
    {
        $this->resetValidation();
        $this->tenantId = Filament::getTenant()?->getKey();
        $this->showTenantModal = true;
    }

    public function switchTenant(): void
    {
        $this->validate(['tenantId' => 'required']);
        $tenant = collect($this->availableTenants())->first(fn (Model $company): bool => (string) $company->getKey() === (string) $this->tenantId);

        if (! $tenant) {
            $this->addError('tenantId', 'Esta empresa não está disponível para você.');

            return;
        }

        $this->redirect(Filament::getUrl($tenant), navigate: true);
    }

    public function availableTenants(): array
    {
        return Filament::getUserTenants(Filament::auth()->user());
    }

    public function render(): View
    {
        return view('livewire.operation-menu');
    }
}
