<?php

namespace App\Filament\Bpo\Resources\CashMovements\Pages;

use App\Filament\Bpo\Concerns\HasWorkbenchHeader;
use App\Filament\Bpo\Resources\CashMovementResource;
use App\Filament\Bpo\Resources\CashMovements\Actions\MovementActions;
use App\Models\AccountPayableInstallmentPayment;
use App\Models\AccountReceivableInstallmentPayment;
use App\Models\CashMovement;
use App\Services\Access\CompanyAuthorization;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Gate;

class ListMovements extends ListRecords
{
    use HasWorkbenchHeader;

    private ?array $movementPermissions = null;

    public function booted(): void
    {
        $this->movementPermissions = null;
        Gate::authorize('viewAny', CashMovement::class);
    }

    public function canOperateMovement(string $ability, ?CashMovement $record = null): bool
    {
        $access = app(CompanyAuthorization::class);
        $this->movementPermissions ??= $access->effectivePermissions(Filament::auth()->user(), Filament::getTenant());
        $scopes = $this->movementPermissions['cash_movements.'.$ability] ?? [];

        if ($record === null) {
            return $scopes !== [];
        }

        return (int) $record->company_id === (int) Filament::getTenant()->id
            && $access->recordMatchesScopes($record, Filament::auth()->user(), $this->movementPermissions['cash_movements.view'] ?? [])
            && $access->recordMatchesScopes($record, Filament::auth()->user(), $scopes);
    }

    public function canOperateTransfer(string $ability, CashMovement $record): bool
    {
        return $record->isTransfer() && $record->transferGroupMovements()
            ->every(fn (CashMovement $item) => $this->canOperateMovement($ability, $item));
    }

    public function canDeleteMovement(CashMovement $record): bool
    {
        if (! $this->canOperateMovement('delete', $record)) {
            return false;
        }

        $module = match ($record->origin_type) {
            AccountPayableInstallmentPayment::class => 'account_payables',
            AccountReceivableInstallmentPayment::class => 'account_receivables',
            default => null,
        };
        if ($module === null || $record->origin_id === null) {
            return true;
        }

        $title = $module === 'account_payables'
            ? $record->payablePayment?->installment?->accountPayable
            : $record->receivablePayment?->installment?->accountReceivable;

        return $title && app(CompanyAuthorization::class)->recordMatchesScopes($title, Filament::auth()->user(), $this->movementPermissions[$module.'.reverse'] ?? [])
            && (! $record->hasStatementLinks() || ($this->canOperateMovement('reverse', $record) && $this->canOperateMovement('create') && $this->canOperateMovement('update', $record)));
    }

    protected static string $layout = 'components.bpo.layout';

    protected string $view = 'filament.bpo.pages.workbench-list';

    public function workbenchEyebrow(): string
    {
        return 'TESOURARIA · MOVIMENTAÇÕES';
    }

    public function workbenchDescription(): string
    {
        return 'Entradas e saídas reunidas por conta, com valores e classificação financeira em uma única visão.';
    }

    protected static string $resource = CashMovementResource::class;

    protected function getHeaderActions(): array
    {
        return [ActionGroup::make(MovementActions::header())->label('Ações')->button()];
    }
}
