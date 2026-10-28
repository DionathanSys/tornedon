<?php

namespace App\Filament\Clusters\Hr\Resources\HrEntries\Pages;

use App\Enum\AccountPayable\Status;
use App\Filament\Clusters\Hr\HrForm;
use App\Filament\Clusters\Hr\Resources\HrEntries\HrEntryResource;
use App\Forms\Components\SignaturePad;
use App\Models\AccountPayable;
use App\Models\FinancialAccount;
use App\Models\HrReceipt;
use App\Services\Hr\HrAccess;
use App\Services\Hr\HrService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\URL;

class EditHrEntry extends EditRecord
{
    protected static string $resource = HrEntryResource::class;

    protected string $view = 'filament.clusters.hr.pages.edit-hr-entry';

    protected function authorizeAccess(): void
    {
        abort_unless(HrEntryResource::canView($this->getRecord()), 403);
    }

    public function getTitle(): string
    {
        return 'Lançamento de RH #'.$this->getRecord()->id;
    }

    public function form(Schema $schema): Schema
    {
        return HrEntryResource::form($schema)->disabled(! HrEntryResource::canEdit($this->getRecord()));
    }

    public function save(bool $shouldRedirect = true, bool $shouldSendSavedNotification = true): void
    {
        abort_unless(HrEntryResource::canEdit($this->getRecord()), 403);
        parent::save($shouldRedirect, $shouldSendSavedNotification);
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return $this->record = app(HrService::class)->saveEntry(auth()->user(), Filament::getTenant(), $data, $record);
    }

    protected function getFormActions(): array
    {
        return HrEntryResource::canEdit($this->getRecord()) ? parent::getFormActions() : [];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('confirm')->label('Confirmar e gerar conta')->icon('heroicon-o-check-circle')->requiresConfirmation()
                ->visible(fn () => $this->getRecord()->status === 'draft' && HrEntryResource::allowed('approve', $this->getRecord()))
                ->action(function (): void {
                    if (HrEntryResource::canEdit($this->getRecord())) {
                        $this->save(false, false);
                    }
                    $this->record = app(HrService::class)->confirm(auth()->user(), Filament::getTenant(), $this->getRecord());
                    $this->fillForm();
                    Notification::make()->title('Lançamento confirmado e conta a pagar vinculada')->success()->send();
                }),
            Action::make('statement')->label('Demonstrativo em PDF')->icon('heroicon-o-document-text')->color('gray')
                ->visible(fn () => HrEntryResource::allowed('export', $this->getRecord()))
                ->url(fn () => URL::temporarySignedRoute('hr.statement', now()->addMinutes(10), ['company' => Filament::getTenant()->id, 'entry' => $this->getRecord()->id]))->openUrlInNewTab(),
            Action::make('cancelEntry')->label('Cancelar lançamento')->color('danger')->requiresConfirmation()
                ->visible(fn () => $this->getRecord()->status !== 'cancelled' && HrEntryResource::allowed('cancel', $this->getRecord()))
                ->action(function (): void {
                    app(HrService::class)->cancel(auth()->user(), Filament::getTenant(), $this->getRecord());
                    $this->record->refresh();
                    $this->fillForm();
                    Notification::make()->title('Lançamento cancelado')->success()->send();
                }),
        ];
    }

    public function registerPaymentAction(): Action
    {
        return Action::make('registerPayment')->label('Registrar pagamento')->icon('heroicon-o-banknotes')->color('success')
            ->visible(fn () => $this->canPerformFinancialAction('settle'))
            ->disabled(fn () => $this->workflowData()['balance'] <= 0)
            ->modalHeading('Registrar pagamento desta competência')->modalWidth('4xl')->disabledSchema(false)
            ->fillForm(fn () => ['payment_date' => now()->toDateString(), 'amount' => $this->workflowData()['balance'], 'interest_amount' => 0, 'fine_amount' => 0, 'discount_amount' => 0, 'financial_account_id' => FinancialAccount::defaultIdForCompany(Filament::getTenant()->id)])
            ->schema(fn (Schema $schema) => $schema->columns(['default' => 1, 'lg' => 2])->components([
                DatePicker::make('payment_date')->label('Data do pagamento')->required(),
                HrForm::money('amount')->label('Valor pago')->required(),
                Select::make('financial_account_id')->label('Conta financeira')->searchable()->preload()->native(false)->required()
                    ->options(fn () => FinancialAccount::optionsForCompany(Filament::getTenant()->id))->columnSpanFull(),
                HrForm::money('interest_amount')->label('Juros'), HrForm::money('fine_amount')->label('Multa'),
                HrForm::money('discount_amount')->label('Desconto financeiro'),
                Textarea::make('notes')->label('Observações da baixa')->rows(2)->columnSpanFull(),
            ]))->modalDescription('A baixa é registrada no financeiro mantendo a competência original do RH.')
            ->action(function (array $data): void {
                app(HrService::class)->registerPayment(auth()->user(), Filament::getTenant(), $this->getRecord(), $data);
                $this->record->refresh();
                Notification::make()->title('Pagamento registrado. O recibo já pode ser emitido abaixo.')->success()->send();
            });
    }

    public function issueReceiptAction(): Action
    {
        return Action::make('issueReceipt')->label('Emitir recibo')->icon('heroicon-o-printer')->disabledSchema(false)
            ->visible(fn () => $this->getRecord()->status === 'confirmed' && $this->hrAllowed('issue'))
            ->fillForm(fn (array $arguments) => ['payment_id' => $arguments['payment_id'] ?? null])
            ->schema([
                Select::make('payment_id')->label('Pagamento deste lançamento')->required()->native(false)
                    ->options(fn () => app(HrService::class)->payments($this->getRecord())->get()->mapWithKeys(fn ($payment) => [$payment->id => '#'.$payment->id.' — '.$payment->payment_date->format('d/m/Y').' — R$ '.number_format($payment->amount, 2, ',', '.')])),
            ])->action(function (array $data): void {
                $receipt = app(HrService::class)->issueReceipt(auth()->user(), Filament::getTenant(), $this->getRecord(), (int) $data['payment_id']);
                Notification::make()->title('Recibo #'.$receipt->id.' disponível nesta página')->success()->send();
            });
    }

    public function reversePaymentAction(): Action
    {
        return Action::make('reversePayment')->label('Estornar pagamento')->color('danger')->requiresConfirmation()
            ->modalDescription('Os recibos desta baixa, inclusive os assinados, serão invalidados. O histórico será preservado.')
            ->visible(fn () => $this->canPerformFinancialAction('reverse'))
            ->action(function (array $arguments): void {
                app(HrService::class)->reversePayment(auth()->user(), Filament::getTenant(), $this->getRecord(), (int) ($arguments['payment_id'] ?? 0));
                $this->record->refresh();
                Notification::make()->title('Pagamento estornado')->success()->send();
            });
    }

    public function signReceiptAction(): Action
    {
        return Action::make('signReceipt')->label('Coletar assinatura')->icon('heroicon-o-pencil-square')->disabledSchema(false)
            ->visible(fn () => $this->hrAllowed('sign') && $this->hrAllowed('view'))
            ->modalHeading('Assinatura do beneficiário')->modalDescription('A assinatura será incluída no PDF com a data e hora desta coleta.')->modalWidth('4xl')
            ->fillForm(fn (array $arguments) => ['signed_by_name' => $this->receiptForAction($arguments)->snapshot['person_name'], 'signature' => null])
            ->schema([
                TextInput::make('signed_by_name')->label('Nome do beneficiário que assina')->required()->maxLength(255),
                SignaturePad::make('signature')->label('Assine no quadro abaixo')->minimal()->canvasHeight('280px')->required()->columnSpanFull(),
            ])->extraModalFooterActions([
                Action::make('clearSignature')->label('Limpar assinatura')->color('gray')->alpineClickHandler("window.dispatchEvent(new CustomEvent('clear-customer-signature'))"),
            ])->modalSubmitActionLabel('Salvar assinatura no recibo')->closeModalByClickingAway(false)
            ->action(function (array $arguments, array $data): void {
                app(HrService::class)->signReceipt(auth()->user(), Filament::getTenant(), $this->receiptForAction($arguments), $data);
                Notification::make()->title('Recibo assinado. O PDF já inclui a assinatura e a data do ato.')->success()->send();
            });
    }

    public function workflowData(): array
    {
        $this->authorizeAccess();
        $entry = $this->getRecord()->fresh();
        $payable = $entry->account_payable_id ? AccountPayable::withoutGlobalScopes()->where('company_id', Filament::getTenant()->id)->find($entry->account_payable_id) : null;
        $installments = $payable?->installments()->withoutGlobalScopes()->where('company_id', Filament::getTenant()->id)->get() ?? collect();
        $balance = $payable
            ? (float) $installments->filter(fn ($installment) => $installment->status !== Status::CANCELLED && ! $installment->ignored_at)->sum('balance_amount')
            : ($entry->status === 'draft' ? (float) $entry->net_amount : 0);

        return ['entry' => $entry, 'payable' => $payable, 'installments' => $installments,
            'paid' => app(HrService::class)->paidAmount($entry), 'balance' => $balance,
            'payments' => app(HrService::class)->payments($entry)->with('financialAccount')->orderByDesc('payment_date')->get(),
            'receipts' => HrReceipt::query()->where('company_id', Filament::getTenant()->id)->where('hr_entry_id', $entry->id)->with('signature')->latest()->get()];
    }

    public function canPerformFinancialAction(string $action): bool
    {
        return app(HrService::class)->canPerformFinancialAction(auth()->user(), Filament::getTenant(), $this->getRecord(), $action);
    }

    public function hrAllowed(string $action, ?HrReceipt $receipt = null): bool
    {
        return app(HrAccess::class)->allows(auth()->user(), Filament::getTenant(), 'hr_receipts.'.$action, $receipt);
    }

    public function receiptUrl(HrReceipt $receipt): string
    {
        return URL::temporarySignedRoute('hr.receipt', now()->addMinutes(10), ['company' => Filament::getTenant()->id, 'receipt' => $receipt->id]);
    }

    private function receiptForAction(array $arguments): HrReceipt
    {
        $this->authorizeAccess();

        return HrReceipt::query()->where('company_id', Filament::getTenant()->id)->where('hr_entry_id', $this->getRecord()->id)->findOrFail((int) ($arguments['receipt_id'] ?? 0));
    }
}
