<?php

namespace App\Filament\Bpo\Resources\Pages;

use App\Filament\Bpo\Concerns\HasFinancialTitlePermissions;
use App\Filament\Bpo\Concerns\HasWorkbenchHeader;
use App\Filament\Bpo\Resources\AccountPayables\RelationManagers as PayableRelations;
use App\Filament\Bpo\Resources\AccountReceivables\RelationManagers as ReceivableRelations;
use App\Filament\Bpo\Resources\Actions\FinancialTitleActions;
use App\Filament\Clusters\Financial\Concerns\RefreshesAccountSummary;
use Filament\Facades\Filament;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

abstract class EditFinancialTitle extends EditRecord
{
    use HasFinancialTitlePermissions;
    use HasWorkbenchHeader;
    use RefreshesAccountSummary;

    protected function getHeaderActions(): array
    {
        return [FinancialTitleActions::delete(static::getResource())];
    }

    protected static string $layout = 'components.bpo.layout';

    protected string $view = 'filament.bpo.pages.edit-financial-title';

    public function getRelationManagers(): array
    {
        // Both managers are rendered together by the custom view instead of tabs.
        return [];
    }

    public function relationshipDetails(): array
    {
        $record = $this->getRecord();
        $payable = static::getResource()::$module === 'account_payables';

        return [
            'id' => $record->id,
            'module' => static::getResource()::$module,
            'payable' => $payable,
            'installments_count' => $record->installments()->count(),
            'payments_count' => $record->payments()->count(),
            'installments_manager' => $payable ? PayableRelations\InstallmentsRelationManager::class : ReceivableRelations\InstallmentsRelationManager::class,
            'payments_manager' => $payable ? PayableRelations\PaymentsRelationManager::class : ReceivableRelations\PaymentsRelationManager::class,
        ];
    }

    public function workbenchEyebrow(): string
    {
        return (static::getResource()::$module === 'account_payables' ? 'DESPESA' : 'RECEITA').' · LANÇAMENTO #'.$this->getRecord()->getKey();
    }

    public function workbenchDescription(): string
    {
        return 'Revise os dados do lançamento e salve as alterações para atualizar a visão financeira.';
    }

    protected ?bool $hasDatabaseTransactions = true;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $data['company_id'] = Filament::getTenant()->id;
        $service = static::getResource()::service();
        $updated = $service->update($record, $data, auth()->id());
        if (! $updated || $service->hasError()) {
            throw ValidationException::withMessages($service->getErrors() ?: ['data' => $service->getMessageUser()]);
        }

        return $updated;
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->record]);
    }
}
