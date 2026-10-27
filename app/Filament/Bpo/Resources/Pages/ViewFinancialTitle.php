<?php

namespace App\Filament\Bpo\Resources\Pages;

use App\Filament\Bpo\Resources\AccountPayables\RelationManagers as PayableRelations;
use App\Filament\Bpo\Resources\AccountReceivables\RelationManagers as ReceivableRelations;
use App\Filament\Clusters\Financial\Concerns\RefreshesAccountSummary;
use App\Models\AccountPayable;
use App\Models\AccountReceivable;
use Carbon\Carbon;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Gate;

abstract class ViewFinancialTitle extends ViewRecord
{
    use RefreshesAccountSummary;

    protected static string $layout = 'components.bpo.layout';

    protected string $view = 'filament.bpo.pages.financial-title';

    public function getRelationManagers(): array
    {
        // The custom view renders both managers together instead of using tabs.
        return [];
    }

    public function editAccountAction(): EditAction
    {
        return EditAction::make('editAccount')->label('Editar lançamento')->icon('heroicon-o-pencil-square')
            ->url(fn () => static::getResource()::getUrl('edit', ['record' => $this->getRecord()]))
            ->visible(fn () => static::getResource()::canEdit($this->getRecord()));
    }

    public function accountDetails(): array
    {
        $record = static::getResource()::getEloquentQuery()->findOrFail($this->getRecord()->getKey());
        Gate::authorize('view', $record);
        abort_unless($record instanceof AccountPayable || $record instanceof AccountReceivable, 404);
        abort_unless((int) $record->company_id === (int) Filament::getTenant()?->id, 403);
        $payable = $record instanceof AccountPayable;
        $partner = $payable ? $record->supplier : $record->customer;
        $installments = $record->installments()->with(['financialCategory', 'costCenter', 'resultCenter'])->get();
        $considered = $installments->filter(fn ($installment) => ! $installment->ignored_at && $installment->status->value !== 'cancelled');
        $nextDue = $record->installments()->open()->min('due_date');
        $ignored = $installments->whereNotNull('ignored_at')->count();
        $classification = fn (string $relation) => $installments->map(fn ($installment) => $installment->{$relation}?->name)->filter()->unique()->join(', ');

        return [
            'id' => $record->id, 'module' => static::getResource()::$module, 'payable' => $payable,
            'title' => $payable ? 'Conta a pagar' : 'Conta a receber',
            'partner_label' => $payable ? 'Fornecedor' : 'Cliente', 'partner' => $record->counterparty_label,
            'partner_document' => $partner?->document_number, 'document' => $record->document_number ?: 'Não informado',
            'description' => $record->description ?: 'Nenhuma descrição informada para este lançamento.',
            'status' => $record->status?->description() ?? 'Não informado', 'status_color' => $record->status?->color() ?? 'gray',
            'consideration' => $ignored ? ($considered->contains(fn ($installment) => (float) $installment->balance_amount > 0) ? 'Parcialmente ignorada' : 'Ignorada no módulo') : null,
            'total' => (float) $record->due_amount, 'settled' => (float) $record->paid_amount,
            'balance' => (float) $considered->sum('balance_amount'),
            'next_due' => $nextDue ? Carbon::parse($nextDue)->format('d/m/Y') : 'Sem parcelas em aberto',
            'payment_method' => $record->payment_method?->description() ?? 'Não informada',
            'category' => $classification('financialCategory') ?: 'Não informada',
            'cost_center' => $classification('costCenter') ?: 'Não informado',
            'result_center' => $classification('resultCenter') ?: 'Não informado',
            'created_at' => $record->created_at?->format('d/m/Y H:i'),
            'installments_count' => $installments->count(), 'payments_count' => $record->payments()->count(),
            'installments_manager' => $payable ? PayableRelations\InstallmentsRelationManager::class : ReceivableRelations\InstallmentsRelationManager::class,
            'payments_manager' => $payable ? PayableRelations\PaymentsRelationManager::class : ReceivableRelations\PaymentsRelationManager::class,
            'back_url' => static::getResource()::getUrl(),
        ];
    }
}
