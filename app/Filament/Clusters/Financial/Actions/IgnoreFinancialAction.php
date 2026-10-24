<?php

namespace App\Filament\Clusters\Financial\Actions;

use App\Models\AccountPayable;
use App\Models\AccountPayableInstallment;
use App\Models\AccountReceivableInstallment;
use App\Services\Financial\ChangeInstallmentStatusService;
use App\Services\Financial\IgnoreFinancialInstallmentsService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Livewire\Component;

final class IgnoreFinancialAction
{
    public static function make(bool $restore = false, bool $bulk = false): Action
    {
        $class = $bulk ? BulkAction::class : Action::class;
        $run = function (Collection $records, array $data, Component $livewire) use ($restore): void {
            $count = app(IgnoreFinancialInstallmentsService::class)->change($records, ! $restore,
                $data['reason'], Filament::auth()->user(), Filament::getTenant(), Filament::getCurrentPanel()?->getId());
            Notification::make()->title("{$count} parcela(s) atualizada(s).")->success()->send();
            $livewire->dispatch('refresh-installments');
            $livewire->dispatch('refresh-payments');
        };
        $action = $class::make($restore ? 'restore_consideration' : 'ignore_financial')
            ->label($restore ? 'Voltar a considerar' : 'Ignorar no módulo de contas')
            ->icon($restore ? 'heroicon-o-arrow-uturn-left' : 'heroicon-o-eye-slash')
            ->color($restore ? 'info' : 'warning')
            ->visible(function ($record = null) use ($restore): bool {
                $user = Filament::auth()->user();
                $company = Filament::getTenant();
                if (! $user || ! $company) {
                    return false;
                }
                $models = $record ? [($record instanceof AccountPayable || $record instanceof AccountPayableInstallment) ? AccountPayableInstallment::class : AccountReceivableInstallment::class]
                    : [AccountPayableInstallment::class, AccountReceivableInstallment::class];
                $allowed = collect($models)->contains(fn ($model) => app(ChangeInstallmentStatusService::class)->allows($user, $company, $model, Filament::getCurrentPanel()?->getId()));

                return $allowed && (! $record || ! ($record instanceof AccountPayableInstallment || $record instanceof AccountReceivableInstallment) || (bool) $record->ignored_at === $restore);
            })
            ->requiresConfirmation()
            ->modalDescription('Preserva valores e histórico. Ignorar retira o saldo das pendências e automações, sem registrar baixa. Em uma conta, afeta suas parcelas em aberto. Restaurar não registra baixa nem emite cobrança nesta ação; as automações configuradas voltam a considerar a parcela.')
            ->schema([Textarea::make('reason')->label('Motivo')->required()->minLength(5)->maxLength(1000)])
            ->action($bulk
                ? fn (Collection $records, array $data, Component $livewire) => $run($records, $data, $livewire)
                : fn (Model $record, array $data, Component $livewire) => $run(new Collection([$record]), $data, $livewire));
        if ($bulk) {
            $action->deselectRecordsAfterCompletion();
        }

        return $action;
    }
}
