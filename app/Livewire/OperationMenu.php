<?php

namespace App\Livewire;

use App\Filament\Operation\Actions\CreateRequisitionAction;
use App\Filament\Operation\Actions\CreateServiceOrderAction;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class OperationMenu extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    public function switchTenantAction(): Action
    {
        return Action::make('switchTenant')
            ->label('Mudar empresa')
            ->icon(Heroicon::ArrowsRightLeft)
            ->modalHeading('Mudar empresa')
            ->modalSubmitActionLabel('Mudar empresa')
            ->schema(fn (Schema $schema): Schema => $schema->components([
                Select::make('tenant_id')
                    ->label('Empresa')
                    ->options(fn (): array => collect($this->getAvailableTenants())
                        ->mapWithKeys(fn (Model $tenant): array => [
                            (string) $tenant->getKey() => Filament::getTenantName($tenant),
                        ])
                        ->all())
                    ->default(fn (): mixed => Filament::getTenant()?->getKey())
                    ->required()
                    ->searchable()
                    ->native(false)
                    ->selectablePlaceholder(false),
            ]))
            ->action(function (array $data): void {
                $tenant = collect($this->getAvailableTenants())
                    ->first(fn (Model $availableTenant): bool => (string) $availableTenant->getKey() === (string) ($data['tenant_id'] ?? ''));

                if (! $tenant) {
                    throw ValidationException::withMessages([
                        'tenant_id' => 'A empresa selecionada não está disponível para este usuário.',
                    ]);
                }

                redirect()->to(Filament::getUrl($tenant));
            });
    }

    public function createServiceOrderAction(): Action
    {
        return CreateServiceOrderAction::make();
    }

    public function createRequisitionAction(): Action
    {
        return CreateRequisitionAction::make();
    }

    public function render(): View
    {
        return view('livewire.operation-menu');
    }

    /**
     * @return array<Model>
     */
    private function getAvailableTenants(): array
    {
        return Filament::getUserTenants(Filament::auth()->user());
    }
}
