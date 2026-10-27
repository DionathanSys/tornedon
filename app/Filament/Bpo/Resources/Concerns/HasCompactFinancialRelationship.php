<?php

namespace App\Filament\Bpo\Resources\Concerns;

use App\Models\AccountPayable;
use App\Services\Access\CompanyAuthorization;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Support\Enums\Width;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;

trait HasCompactFinancialRelationship
{
    public function booted(): void
    {
        Gate::authorize('view', $this->getOwnerRecord());
        abort_unless((int) $this->getOwnerRecord()->company_id === (int) Filament::getTenant()?->id, 403);
    }

    protected function compactFinancialTable(Table $table, bool $payments): Table
    {
        $visible = $payments ? ['installment.sequence_number', 'payment_date', 'amount', 'financialAccount.name'] : ['sequence_number', 'due_date', 'due_amount', 'status'];
        foreach ($table->getColumns() as $column) {
            $column->toggleable(isToggledHiddenByDefault: ! in_array($column->getName(), $visible, true));
            if (! $payments && $column->getName() === 'due_amount') {
                $column->label('Valor / saldo')->description(fn ($record) => 'Saldo: R$ '.number_format((float) $record->balance_amount, 2, ',', '.'));
            }
        }
        $actions = $table->getRecordActions();
        foreach ($actions as $item) {
            foreach ($item instanceof ActionGroup ? $item->getFlatActions() : [$item] as $action) {
                $name = $action->getName();
                $permission = match ($name) {
                    'register_payment', 'edit_payment' => 'settle',
                    'delete_payment' => 'reverse',
                    'ignore_financial', 'restore_consideration' => 'change_status_without_movement',
                    'copy_pix_payment_link', 'open_pix_payment_link' => 'view',
                    default => 'update',
                };
                $action->authorize(fn () => app(CompanyAuthorization::class)->allowsRecord(Filament::auth()->user(), Filament::getTenant(),
                    ($this->getOwnerRecord() instanceof AccountPayable ? 'account_payables' : 'account_receivables').'.'.$permission, $this->getOwnerRecord()));
            }
        }
        if ($payments) {
            $table->recordActions([ActionGroup::make($actions)->icon('heroicon-o-ellipsis-horizontal')->tooltip('Ações da baixa')]);
        }

        return $table->heading($payments ? ($this->getOwnerRecord() instanceof AccountPayable ? 'Pagamentos' : 'Recebimentos') : 'Parcelas')
            ->description($payments ? 'Histórico de baixas deste lançamento.' : 'Vencimentos, valores e ações por parcela.')
            ->queryStringIdentifier($payments ? 'payments' : 'installments')
            ->recordActionsPosition(RecordActionsPosition::AfterColumns)
            ->columnManagerColumns(['default' => 1, 'lg' => 2])->columnManagerWidth(Width::ThreeExtraLarge)
            ->paginated([5, 10, 25])->defaultPaginationPageOption(5);
    }
}
