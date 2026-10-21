<?php

namespace App\Filament\Clusters\Financial\Resources\CommitmentPaymentImports\Actions;

use App\Enum\Commitment\PaymentLineStatus;
use App\Filament\Clusters\Financial\Resources\AccountReceivables\AccountReceivableResource;
use App\Filament\Clusters\Financial\Resources\CustomerCommitments\CustomerCommitmentResource;
use App\Filament\Clusters\Financial\Resources\Invoices\InvoiceResource;
use App\Filament\Clusters\Sales\Resources\FiscalDocuments\FiscalDocumentResource;
use App\Models\CommitmentPaymentLine;
use App\Models\CommitmentSetting;
use App\Models\FinancialAccount;
use App\Services\Commitments\MatchCommitmentPaymentsService;
use App\Services\Commitments\ReceiveCommitmentPaymentService;
use App\Services\Commitments\ReviewCommitmentPaymentsService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

class CommitmentPaymentActions
{
    public static function review(): Action
    {
        return Action::make('review_payment')->label('Revisar pagamento')->icon('heroicon-o-eye')->authorize('view')->modalWidth('6xl')
            ->modalHeading('Conferência do pagamento informado')->modalSubmitAction(false)->modalCancelActionLabel('Fechar')
            ->schema([
                ...self::previewSchema(),
                RepeatableEntry::make('current_match')->label('Conferência atual')->state(fn (CommitmentPaymentLine $record) => [app(MatchCommitmentPaymentsService::class)->analyze($record)])
                    ->schema([
                        TextEntry::make('reason')->label('Diagnóstico'),
                        TextEntry::make('commitment_id')->label('Empenho no sistema')->placeholder('Não localizado')
                            ->url(fn (TextEntry $component) => ($id = $component->getContainer()->getConstantState()['commitment_id']) ? CustomerCommitmentResource::getUrl('view', ['record' => $id]) : null)->openUrlInNewTab(),
                        TextEntry::make('invoice_id')->label('Fatura')->placeholder('Não localizada')
                            ->url(fn (TextEntry $component) => ($id = $component->getContainer()->getConstantState()['invoice_id']) ? InvoiceResource::getUrl('edit', ['record' => $id]) : null)->openUrlInNewTab(),
                        TextEntry::make('document_id')->label('NFS-e')->placeholder('Não localizada')
                            ->url(fn (TextEntry $component) => ($id = $component->getContainer()->getConstantState()['document_id']) ? FiscalDocumentResource::getUrl('edit', ['record' => $id]) : null)->openUrlInNewTab(),
                        TextEntry::make('balance_cents')->label('Saldo atual')->money('BRL', divideBy: 100, locale: 'pt_BR')->placeholder('—'),
                        RepeatableEntry::make('installments')->label('Distribuição da quitação por vencimento')->schema([
                            TextEntry::make('id')->label('Parcela'), TextEntry::make('due_date')->label('Vencimento')->date('d/m/Y'),
                            TextEntry::make('account_id')->label('Conta a receber')
                                ->url(fn (TextEntry $component) => AccountReceivableResource::getUrl('edit', ['record' => $component->getContainer()->getConstantState()['account_id']]))->openUrlInNewTab(),
                            TextEntry::make('balance_cents')->label('Saldo')->money('BRL', divideBy: 100, locale: 'pt_BR'),
                            TextEntry::make('competence_date')->label('Competência')->date('d/m/Y')->placeholder('—'),
                        ]),
                    ]),
                RepeatableEntry::make('receipt_evidence')->label('Recebimentos vinculados — evidência preservada')
                    ->state(fn (CommitmentPaymentLine $record) => $record->receipt?->paymentLinks()->forCustomer($record->company_id, $record->customer_id)->get()->map(fn ($link) => [
                        'payment_id' => $link->payment_id, 'original_payment_id' => data_get($link->payment_snapshot, 'evidence.id'),
                        'amount_cents' => data_get($link->payment_snapshot, 'evidence.amount_cents'), 'payment_date' => data_get($link->payment_snapshot, 'evidence.payment_date'),
                    ])->all() ?? [])
                    ->schema([TextEntry::make('original_payment_id')->label('Recebimento original'), TextEntry::make('payment_id')->label('Recebimento atual')->placeholder('Removido/estornado'),
                        TextEntry::make('amount_cents')->label('Valor registrado')->money('BRL', divideBy: 100, locale: 'pt_BR'), TextEntry::make('payment_date')->label('Data registrada')->date('d/m/Y')]),
                TextEntry::make('object')->label('Objeto'), TextEntry::make('original_data.original_text')->label('Linha original')->placeholder('—'),
            ]);
    }

    public static function refresh(): Action
    {
        return Action::make('refresh_payment')->label('Buscar vínculos')->icon('heroicon-o-arrow-path')->authorize('update')
            ->action(function (CommitmentPaymentLine $record): void {
                $record = app(MatchCommitmentPaymentsService::class)->refresh(Filament::getTenant()->id, $record->id, Auth::id());
                Notification::make()->title($record->status->description())->body(data_get($record->match_diagnostics, 'reason'))->info()->send();
            });
    }

    public static function refreshAll(): Action
    {
        return Action::make('refresh_payments')->label('Buscar vínculos do relatório')->icon('heroicon-o-arrow-path')
            ->authorize('viewAny', CommitmentPaymentLine::class)->action(function ($livewire): void {
                $owner = $livewire->getOwnerRecord();
                foreach ($owner->files()->forCustomer(Filament::getTenant()->id, $owner->customer_id)->whereIn('status', ['processed', 'needs_review'])->pluck('id') as $fileId) {
                    app(MatchCommitmentPaymentsService::class)->queueFile(Filament::getTenant()->id, $fileId, Auth::id());
                }
                Notification::make()->title('Busca de vínculos solicitada')->body('A conciliação será atualizada em blocos pela fila. Nenhuma baixa é realizada nesta busca.')->success()->send();
            });
    }

    public static function receive(bool $linkExisting = false): Action
    {
        return Action::make($linkExisting ? 'link_existing_receipt' : 'receive_payment')
            ->label($linkExisting ? 'Vincular recebimento existente' : 'Confirmar recebimento')->icon('heroicon-o-banknotes')->authorize('update')
            ->visible(fn (CommitmentPaymentLine $record) => $linkExisting
                ? $record->status === PaymentLineStatus::ALREADY_RECEIVED && ! $record->commitment_receipt_id && data_get($record->match_diagnostics, 'can_link_existing', false)
                : $record->status === PaymentLineStatus::MATCHED)
            ->modalHeading($linkExisting ? 'Vincular pagamentos já registrados' : 'Confirmar quitação integral do empenho')->modalWidth('5xl')
            ->modalDescription($linkExisting ? 'Confirme o vínculo aos pagamentos já existentes. Nenhuma nova baixa será registrada.' : 'O recebimento utilizará a data efetiva do relatório e será distribuído pelo saldo das parcelas, em ordem de vencimento. O saldo será conferido novamente ao confirmar.')
            ->schema([
                ...self::previewSchema(),
                self::accountSelect()->default(fn (CommitmentPaymentLine $record) => $linkExisting ? data_get($record->match_diagnostics, 'existing_account_id')
                    : CommitmentSetting::forCustomer($record->company_id, $record->customer_id)->value('financial_account_id') ?? FinancialAccount::defaultIdForCompany($record->company_id)),
                self::competence()->visible(! $linkExisting),
            ])->action(function (CommitmentPaymentLine $record, array $data) use ($linkExisting): void {
                app(ReceiveCommitmentPaymentService::class)->receive(Filament::getTenant()->id, $record->id, Auth::id(), (int) $data['financial_account_id'], $data['competence_date'] ?? null, $linkExisting);
                Notification::make()->title($linkExisting ? 'Recebimento existente vinculado' : 'Recebimento confirmado')->success()->send();
            });
    }

    public static function bulkReceive(): BulkAction
    {
        return BulkAction::make('receive_selected')->label('Confirmar recebimentos selecionados')->icon('heroicon-o-banknotes')
            ->authorize('viewAny', CommitmentPaymentLine::class)->deselectRecordsAfterCompletion()->modalWidth('6xl')
            ->modalHeading('Confirmar recebimentos do relatório')->modalDescription('Até 25 linhas por operação. Cada empenho é confirmado em sua própria transação. Divergências não geram baixa; uma falha não desfaz outros empenhos concluídos.')
            ->schema([
                TextEntry::make('selection_summary')->label('Seleção')->state(fn (Collection $records) => $records->count().' linha(s) — R$ '.number_format($records->sum('amount'), 2, ',', '.')),
                RepeatableEntry::make('selected_payments')->label('Datas e valores utilizados')->state(fn (Collection $records) => $records->map(fn ($record) => [
                    'reference' => $record->commitment_number.'/'.$record->fiscal_year, 'date' => $record->payment_date, 'amount' => $record->amount,
                    'diagnostic' => data_get($record->match_diagnostics, 'reason'),
                ])->all())->schema([TextEntry::make('reference')->label('Empenho'), TextEntry::make('date')->label('Pagamento')->date('d/m/Y'),
                    TextEntry::make('amount')->label('Valor')->money('BRL', locale: 'pt_BR'), TextEntry::make('diagnostic')->label('Diagnóstico')]),
                self::accountSelect()->default(fn () => FinancialAccount::defaultIdForCompany(Filament::getTenant()->id)), self::competence(),
            ])->action(function (Collection $records, array $data): void {
                Validator::make(['count' => $records->count()], ['count' => 'integer|between:1,25'])->validate();
                $completed = 0;
                $errors = [];
                foreach ($records->sortBy('id') as $record) {
                    try {
                        app(ReceiveCommitmentPaymentService::class)->receive(Filament::getTenant()->id, $record->id, Auth::id(), (int) $data['financial_account_id'], $data['competence_date'] ?? null);
                        $completed++;
                    } catch (Throwable $exception) {
                        if (! $exception instanceof ValidationException) {
                            report($exception);
                        }
                        $errors[] = $record->commitment_number.'/'.$record->fiscal_year.': '.($exception instanceof ValidationException ? $exception->validator->errors()->first() : 'Falha interna; consulte o log.');
                    }
                }
                Notification::make()->title('Recebimentos: '.$completed.' concluído(s), '.count($errors).' pendente(s)')->body(implode("\n", $errors))
                    ->color($errors ? 'warning' : 'success')->send();
            });
    }

    public static function correct(): Action
    {
        return Action::make('correct_payment')->label('Conferir dados extraídos')->icon('heroicon-o-pencil-square')->authorize('update')
            ->visible(fn (CommitmentPaymentLine $record) => ! $record->commitment_receipt_id && $record->status !== PaymentLineStatus::IGNORED)
            ->modalDescription('Confira com o PDF original. A revisão exige motivo e preserva os dados extraídos. Não cria recebimentos.')
            ->fillForm(fn (CommitmentPaymentLine $record) => ['commitment_number' => $record->commitment_number, 'fiscal_year' => $record->fiscal_year,
                'patrimony' => $record->patrimony, 'fiscal_document_number' => $record->fiscal_document_number,
                'fiscal_document_date' => $record->fiscal_document_date?->toDateString(), 'payment_date' => $record->payment_date->toDateString(), 'amount' => $record->amount])
            ->schema([
                TextInput::make('commitment_number')->label('Empenho')->required()->maxLength(40), TextInput::make('fiscal_year')->label('Exercício')->numeric()->required(),
                TextInput::make('patrimony')->label('Patrimônio')->maxLength(60), TextInput::make('fiscal_document_number')->label('Número da NFS-e')->required()->maxLength(60),
                DatePicker::make('fiscal_document_date')->label('Emissão da NFS-e')->required(), DatePicker::make('payment_date')->label('Data do pagamento no relatório')->required(),
                TextInput::make('amount')->label('Valor pago')->numeric()->prefix('R$')->step(0.01)->minValue(0.01)->required(),
                Checkbox::make('acknowledge_issues')->label('Conferi e confirmo os dados, incluindo as pendências desta linha no PDF original')
                    ->visible(fn (CommitmentPaymentLine $record) => filled(data_get($record->original_data, 'issues'))),
                Textarea::make('reason')->label('Motivo da revisão')->required()->minLength(3)->maxLength(2000),
            ])->action(function (CommitmentPaymentLine $record, array $data): void {
                app(ReviewCommitmentPaymentsService::class)->correct(Filament::getTenant()->id, $record->id, Auth::id(), $data);
                Notification::make()->title('Dados conferidos e vínculo recalculado')->success()->send();
            });
    }

    public static function ignore(bool $reopen = false): Action
    {
        return Action::make($reopen ? 'reopen_payment' : 'ignore_payment')->label($reopen ? 'Reabrir linha' : 'Ignorar linha')->authorize('update')
            ->visible(fn (CommitmentPaymentLine $record) => ! $record->commitment_receipt_id && ($reopen ? $record->status === PaymentLineStatus::IGNORED : $record->status !== PaymentLineStatus::IGNORED))
            ->schema([Textarea::make('reason')->label('Motivo')->required()->minLength(3)->maxLength(2000)])
            ->action(function (CommitmentPaymentLine $record, array $data) use ($reopen): void {
                app(ReviewCommitmentPaymentsService::class)->ignore(Filament::getTenant()->id, $record->id, Auth::id(), $data['reason'], $reopen);
                Notification::make()->title($reopen ? 'Linha reaberta' : 'Linha ignorada')->success()->send();
            });
    }

    public static function pdf(): Action
    {
        return Action::make('payment_pdf')->label('Visualizar relatório original')->icon('heroicon-o-document-text')->authorize('view')
            ->visible(fn (CommitmentPaymentLine $record) => $record->importFile->currentAttachments()->exists())
            ->url(fn (CommitmentPaymentLine $record) => $record->importFile->currentAttachments()->first()?->preview_url)->openUrlInNewTab();
    }

    private static function accountSelect(): Select
    {
        return Select::make('financial_account_id')->label('Conta financeira do recebimento')->required()->native(false)->searchable()->preload()
            ->options(fn () => FinancialAccount::optionsForCompany(Filament::getTenant()->id));
    }

    private static function competence(): DatePicker
    {
        return DatePicker::make('competence_date')->label('Competência (opcional)')->helperText('Sem alteração, mantém a competência das parcelas. A data do recebimento sempre vem da linha do relatório.');
    }

    private static function previewSchema(): array
    {
        return [TextEntry::make('commitment_number')->label('Empenho'), TextEntry::make('fiscal_year')->label('Exercício'),
            TextEntry::make('fiscal_document_number')->label('NFS-e'), TextEntry::make('payment_date')->label('Pagamento informado')->date('d/m/Y'),
            TextEntry::make('amount')->label('Valor a receber')->money('BRL', locale: 'pt_BR'),
            TextEntry::make('page_start')->label('Página no relatório')];
    }
}
