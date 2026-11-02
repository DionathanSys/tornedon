<?php

namespace App\Filament\Bpo\Resources\FiscalDocuments\Pages;

use App\Filament\Bpo\Concerns\HasWorkbenchHeader;
use App\Filament\Bpo\Concerns\SecuresBpoActions;
use App\Filament\Bpo\Resources\IncomingFiscalDocumentResource;
use App\Filament\Clusters\Financial\Resources\FiscalDocuments\Actions\ConfirmEntryAction;
use App\Filament\Clusters\Financial\Resources\FiscalDocuments\Actions\CreateServiceOrderFromEntryAction;
use App\Filament\Clusters\Financial\Resources\FiscalDocuments\Actions\GeneratePurchaseReturnAction;
use App\Filament\Clusters\Financial\Resources\FiscalDocuments\Pages\EditFiscalDocument;
use App\Models\CompanyPartner;
use Filament\Actions\Action;
use Illuminate\Database\Eloquent\Model;

class EditIncomingDocument extends EditFiscalDocument
{
    use HasWorkbenchHeader;
    use SecuresBpoActions;

    protected static string $resource = IncomingFiscalDocumentResource::class;

    protected static string $layout = 'components.bpo.layout';

    protected string $view = 'filament.bpo.pages.workbench-record';

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        abort_unless(! $record->fresh()->confirmed && ! $record->canceled && ! $record->isImportedFromDfe() && ! $record->isOperationalHistory(), 403);
        $data['company_id'] = $record->company_id;
        $data['operation_type'] = $record->operation_type->value;
        if (isset($data['customer_id']) && (int) $data['customer_id'] !== (int) $record->customer_id) {
            abort_unless(CompanyPartner::where('company_id', $record->company_id)->where('partner_id', $data['customer_id'])->where('is_active', true)->exists(), 403);
        }

        return parent::handleRecordUpdate($record, $data);
    }

    protected function getFormActions(): array
    {
        return $this->getRecord()->confirmed || $this->getRecord()->canceled || $this->getRecord()->isOperationalHistory() ? [] : parent::getFormActions();
    }

    protected function getHeaderActions(): array
    {
        $confirm = $this->secureBpoAction(ConfirmEntryAction::make(), 'fiscal_documents.approve')
            ->authorize(fn ($record) => ! $record->isOperationalHistory() && $this->canUseBpoAction('fiscal_documents.approve', $record)
                && $this->canUseBpoAction('fiscal_documents.update', $record) && $this->canUseBpoAction('stock_movements.create') && $this->canUseBpoAction('product_stocks.update'))
            ->before(function ($record, array $data): void {
                foreach (['fiscal_documents.approve', 'fiscal_documents.update'] as $permission) {
                    $this->authorizeBpoAction($permission, $record);
                }
                foreach (['stock_movements.create', 'product_stocks.update'] as $permission) {
                    $this->authorizeBpoAction($permission);
                }
                if ($data['generate_account_payable_now'] ?? false) {
                    foreach (($data['payment_method'] ?? '') === 'cartao_credito' ? ['company_card_transactions.create', 'company_card_statements.create', 'company_card_statements.update', 'company_credit_cards.view'] : ['account_payables.create', 'account_payables.update'] as $permission) {
                        $this->authorizeBpoAction($permission);
                    }
                }
            })->modalWidth('3xl')->modalCancelActionLabel('Manter pendente');

        return [Action::make('back')->label('Voltar aos documentos')->url(static::getResource()::getUrl()), $confirm,
            $this->secureBpoAction(CreateServiceOrderFromEntryAction::make(), 'fiscal_documents.update')
                ->authorize(fn ($record) => $this->canUseBpoAction('fiscal_documents.update', $record) && $this->canUseBpoAction('service_orders.create'))
                ->mutateDataUsing(fn (array $data) => [...$data, 'open_service_order' => false]),
            $this->secureBpoAction(GeneratePurchaseReturnAction::make(), 'fiscal_documents.update')
                ->authorize(fn ($record) => $this->canUseBpoAction('fiscal_documents.update', $record) && $this->canUseBpoAction('fiscal_documents.create')),
        ];
    }
}
