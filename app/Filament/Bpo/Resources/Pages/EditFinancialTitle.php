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
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
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

    public function form(Schema $schema): Schema
    {
        $schema = parent::form($schema)->columns([
            'default' => 1, 'sm' => 1, 'md' => 1, 'lg' => 1, 'xl' => 1, '2xl' => 1,
        ]);

        foreach ($schema->getComponents(withHidden: true) as $section) {
            if (! $section instanceof Section) {
                continue;
            }

            $section->columns(['default' => 1, 'sm' => 1, 'md' => 2, 'lg' => 2, 'xl' => 6, '2xl' => 6]);

            foreach ($section->getChildComponents() as $field) {
                $name = $field->getName();
                $fullWidth = in_array($name, [
                    'counterparty_change_reason', 'description', 'card_settlement_preview',
                    'auto_register_receipt_on_due_date', 'auto_receipt_financial_account_id',
                    'auto_register_payment_on_due_date', 'auto_payment_financial_account_id',
                ], true);
                $wide = in_array($name, ['supplier_id', 'customer_id', 'manual_counterparty_name'], true);

                $field->columnSpan([
                    'default' => $fullWidth ? 'full' : 1,
                    'sm' => $fullWidth ? 'full' : 1,
                    'md' => $fullWidth ? 'full' : 1,
                    'lg' => $fullWidth ? 'full' : 1,
                    'xl' => $fullWidth ? 'full' : ($wide ? 4 : 2),
                    '2xl' => $fullWidth ? 'full' : ($wide ? 4 : 2),
                ])->columnStart(null);
            }
        }

        return $schema;
    }

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
