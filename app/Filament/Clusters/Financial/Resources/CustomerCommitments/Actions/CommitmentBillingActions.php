<?php

namespace App\Filament\Clusters\Financial\Resources\CustomerCommitments\Actions;

use App\Enum\Payment\Condition;
use App\Enum\Payment\Method;
use App\Filament\Clusters\Financial\Resources\Invoices\InvoiceResource;
use App\Models\CommitmentBillingBatch;
use App\Models\CustomerCommitment;
use App\Models\FinancialAccount;
use App\Models\FinancialCategory;
use App\Services\Commitments\CommitmentBillingData;
use App\Services\Commitments\RequestCommitmentBillingService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class CommitmentBillingActions
{
    public static function make(): Action
    {
        return Action::make('bill_commitment')->label(fn (CustomerCommitment $record) => $record->billing?->invoice?->confirmed
            ? 'Encaminhar NFS-e' : ($record->billing?->invoice_id || $record->billing?->last_error ? 'Retomar faturamento' : 'Faturar OS vinculada'))
            ->icon('heroicon-o-document-currency-dollar')->authorize('update')
            ->visible(fn (CustomerCommitment $record) => $record->status->value === 'approved' && $record->billing?->canRequestBilling())
            ->modalHeading('Faturamento do empenho')->modalWidth('5xl')->stickyModalHeader()->stickyModalFooter()
            ->modalDescription('Uma fatura por OS. O sistema executará o faturamento e a confirmação pelos serviços atuais. A emissão depende da opção abaixo e não representa autorização fiscal.')
            ->modalSubmitActionLabel('Solicitar processamento')
            ->schema([
                Select::make('nfse_service_id')->label('Serviço da NFS-e')->native(false)->searchable()->live()
                    ->visible(fn (CustomerCommitment $record) => ! $record->billing?->invoice?->confirmed)
                    ->options(fn (CustomerCommitment $record) => $record->billing ? app(CommitmentBillingData::class)->serviceOptions($record->billing) : [])
                    ->default(function (CustomerCommitment $record) {
                        try {
                            return app(CommitmentBillingData::class)->build($record->billing)['nfse_service_id'];
                        } catch (ValidationException) {
                            return null;
                        }
                    }),
                RepeatableEntry::make('billing_preview')->hiddenLabel()->state(fn (CustomerCommitment $record, Get $get) => [self::preview($record, filled($get('nfse_service_id')) ? (int) $get('nfse_service_id') : null)])
                    ->schema(self::previewSchema()),
                self::emissionCheckbox()->default(fn (CustomerCommitment $record) => (bool) $record->billing?->invoice?->confirmed),
            ])->action(function (CustomerCommitment $record, array $data): void {
                $batch = app(RequestCommitmentBillingService::class)->request(Filament::getTenant()->id, [$record->id], Auth::id(),
                    (bool) ($data['emit_nfse'] ?? false), filled($data['nfse_service_id'] ?? null) ? (int) $data['nfse_service_id'] : null);
                self::notify($batch);
            });
    }

    public static function bulk(): BulkAction
    {
        return BulkAction::make('bill_selected')->label('Faturar selecionados')->icon('heroicon-o-document-currency-dollar')
            ->authorize('viewAny', CustomerCommitment::class)->deselectRecordsAfterCompletion()
            ->modalHeading('Faturamento dos empenhos selecionados')->modalWidth('6xl')->stickyModalHeader()->stickyModalFooter()
            ->modalDescription('Até 25 empenhos por lote, cada OS com sua própria fatura. Registros sem vínculo aprovado ou já em processamento serão excluídos da execução. Revise os diagnósticos de cada item.')
            ->schema([
                TextEntry::make('selected_summary')->label('Seleção')->state(fn (Collection $records) => $records->count().' empenho(s) — R$ '.number_format($records->sum('amount'), 2, ',', '.')),
                RepeatableEntry::make('billing_preview')->hiddenLabel()->state(fn (Collection $records) => $records->map(fn ($record) => self::preview($record))->all())->schema(self::previewSchema()),
                self::emissionCheckbox(),
            ])->action(function (Collection $records, array $data): void {
                $eligible = $records->filter(fn (CustomerCommitment $record) => $record->status->value === 'approved' && $record->billing?->canRequestBilling());
                if ($eligible->isEmpty()) {
                    throw ValidationException::withMessages(['records' => 'Nenhum empenho aprovado e disponível foi selecionado.']);
                }
                $batch = app(RequestCommitmentBillingService::class)->request(Filament::getTenant()->id, $eligible->modelKeys(), Auth::id(), (bool) ($data['emit_nfse'] ?? false));
                self::notify($batch, $records->count() - $eligible->count());
            });
    }

    public static function invoice(): Action
    {
        return Action::make('open_invoice')->label('Abrir fatura')->icon('heroicon-o-arrow-top-right-on-square')->authorize('view')
            ->visible(fn (CustomerCommitment $record) => $record->billing?->invoice_id !== null)
            ->url(fn (CustomerCommitment $record) => InvoiceResource::getUrl('edit', ['record' => $record->billing->invoice_id]))->openUrlInNewTab();
    }

    public static function preview(CustomerCommitment $record, ?int $serviceId = null): array
    {
        $row = ['commitment' => $record->number.'/'.$record->fiscal_year, 'order' => $record->billing?->serviceOrder?->number,
            'amount' => $record->amount, 'diagnostic' => 'Disponível para processamento.'];
        if ($record->status->value !== 'approved' || ! $record->billing || ! $record->billing->canRequestBilling()) {
            return [...$row, 'diagnostic' => 'Não será executado: aprove o vínculo ou aguarde o processamento atual.'];
        }
        try {
            $data = app(CommitmentBillingData::class)->build($record->billing, $serviceId);

            return [...$row, 'method' => Method::tryFrom($data['payment_method'])?->description(),
                'condition' => Condition::tryFrom($data['payment_condition'])?->description(),
                'category' => filled($data['financial_category_id']) ? FinancialCategory::query()->where('company_id', $record->company_id)->whereKey($data['financial_category_id'])->value('name') : 'Não definida',
                'account' => filled($data['financial_account_id']) ? (FinancialAccount::optionsForCompany($record->company_id)[$data['financial_account_id']] ?? 'Não definida') : 'Não definida',
                'body' => $data['nfse_item_description'], 'footer' => $data['nfse_additional_information']];
        } catch (ValidationException $exception) {
            return [...$row, 'diagnostic' => $exception->validator->errors()->first()];
        }
    }

    private static function previewSchema(): array
    {
        return [TextEntry::make('commitment')->label('Empenho'), TextEntry::make('order')->label('OS')->placeholder('Sem vínculo'),
            TextEntry::make('amount')->label('Valor')->money('BRL', locale: 'pt_BR'),
            TextEntry::make('diagnostic')->label('Diagnóstico'), TextEntry::make('method')->label('Forma de pagamento')->placeholder('—'),
            TextEntry::make('condition')->label('Condição')->placeholder('—'), TextEntry::make('category')->label('Categoria financeira')->placeholder('—'),
            TextEntry::make('account')->label('Conta financeira')->placeholder('—'),
            TextEntry::make('body')->label('Corpo da NFS-e')->placeholder('Revise a configuração'), TextEntry::make('footer')->label('Rodapé da NFS-e')->placeholder('Revise a configuração')];
    }

    private static function emissionCheckbox(): Checkbox
    {
        return Checkbox::make('emit_nfse')->label('Encaminhar NFS-e para emissão após confirmar a fatura')->default(false)
            ->helperText('Opcional. Usa o serviço e a fila fiscal existentes. Sem esta opção, a fatura fica confirmada para emissão posterior. Não registra recebimento.');
    }

    private static function notify(CommitmentBillingBatch $batch, int $skipped = 0): void
    {
        $errors = $batch->items()->where('status', 'failed')->pluck('last_error')->unique()->implode(' ');
        Notification::make()->title('Lote de faturamento #'.$batch->id)->body('Processamento solicitado. Acompanhe a coluna Execução e filtre pelo lote.'
            .($skipped ? " {$skipped} registro(s) indisponível(is) excluído(s)." : '').($errors ? ' Pendências: '.$errors : ''))
            ->color($errors ? 'warning' : 'success')->send();
    }
}
