<?php

namespace App\Filament\Clusters\Financial\Resources\Invoices\Pages\Actions;

use App\Enum\Payment\Condition;
use App\Enum\Payment\Method;
use App\Filament\Clusters\Financial\Resources\Invoices\Pages\EditInvoice;
use App\Models\CardPaymentProfile;
use App\Models\FinancialAccount;
use App\Models\Invoice;
use App\Notification\NotifyService as notify;
use App\Services\FiscalDocument\NfeDocumentService;
use App\Services\FiscalDocument\NfseDocumentService;
use App\Services\Invoice\InvoiceService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

final class ConfirmInvoiceAction
{
    public static function make(): Action
    {
        return Action::make('confirmInvoice')
            ->label('Confirmar')
            ->icon(Heroicon::Check)
            ->color('success')
            ->modalHeading('Confirmar Fatura')
            ->modalDescription('Ao confirmar, o sistema irá gerar automaticamente os documentos fiscais necessários e as contas a receber. Opcionalmente, você pode disparar a emissão dos documentos logo após a confirmação e registrar o recebimento imediato da fatura.')
            ->visible(fn (Invoice $record): bool => ! $record->confirmed && ! $record->canceled)
            ->schema([
                Tabs::make('fiscal_document_settings')
                    ->label('Confirmação')
                    ->columnSpanFull()
                    ->tabs([
                        Tab::make('Geral')
                            ->schema([
                                Callout::make('Documentos que serão gerados')
                                    ->description(fn (Invoice $record): string => self::resolveDocumentTypesDescription($record))
                                    ->info(),

                                Checkbox::make('emit_fiscal_documents')
                                    ->label('Disparar emissão dos documentos fiscais gerados')
                                    ->helperText('Quando marcado, a NF-e e/ou NFS-e criada será enviada imediatamente para processamento.')
                                    ->default(false),

                                Select::make('payment_method')
                                    ->label('Forma de Pagamento')
                                    ->options(Method::toSelectArray())
                                    ->default(fn (Invoice $record): ?string => $record->payment_method?->value)
                                    ->native(false)
                                    ->live()
                                    ->required(),

                                Select::make('card_payment_profile_id')
                                    ->label('Perfil de Recebimento no Cartão')
                                    ->options(fn (): array => CardPaymentProfile::query()
                                        ->where('company_id', Filament::getTenant()?->id)
                                        ->where('active', true)
                                        ->orderBy('name')
                                        ->pluck('name', 'id')
                                        ->toArray())
                                    ->searchable()
                                    ->preload()
                                    ->native(false)
                                    ->visible(fn (Get $get): bool => (string) $get('payment_method') === Method::CREDIT_CARD->value)
                                    ->required(fn (Get $get): bool => (string) $get('payment_method') === Method::CREDIT_CARD->value)
                                    ->helperText('Define as taxas e o prazo D+X aplicados no contas a receber.'),

                                DatePicker::make('payment_date')
                                    ->label('Data da Venda/Pagamento no Cartão')
                                    ->default(fn (Invoice $record): ?string => $record->invoice_date?->toDateString() ?? now()->toDateString())
                                    ->visible(fn (Get $get): bool => (string) $get('payment_method') === Method::CREDIT_CARD->value)
                                    ->required(fn (Get $get): bool => (string) $get('payment_method') === Method::CREDIT_CARD->value)
                                    ->helperText('Usada para calcular a previsão de liquidação do cartão.'),

                                Select::make('payment_condition')
                                    ->label('Condição de Pagamento')
                                    ->options(Condition::toGroupedSelectArray())
                                    ->default(fn (Invoice $record): ?string => $record->payment_condition?->value)
                                    ->native(false)
                                    ->live()
                                    ->afterStateUpdated(function (Set $set, ?string $state): void {
                                        $condition = Condition::tryFrom((string) $state);

                                        if ($condition?->isCash()) {
                                            $set('mark_as_received', true);
                                        }
                                    })
                                    ->required(fn (Get $get): bool => (string) $get('payment_method') !== Method::CREDIT_CARD->value)
                                    ->helperText('Em cartao, informe apenas se precisar parcelar comercialmente. O primeiro vencimento seguira o prazo D+X do perfil da operadora.'),

                                Checkbox::make('mark_as_received')
                                    ->label('Marcar valores da fatura como já recebidos')
                                    ->helperText('Quando marcado, os pagamentos das parcelas do contas a receber serão registrados automaticamente ao confirmar a fatura.')
                                    ->default(fn (Invoice $record): bool => $record->payment_condition?->isCash() ?? false),

                                DatePicker::make('received_at')
                                    ->label('Data do recebimento')
                                    ->default(now())
                                    ->visible(fn (Get $get): bool => (bool) $get('mark_as_received'))
                                    ->required(fn (Get $get): bool => (bool) $get('mark_as_received')),

                                Select::make('financial_account_id')
                                    ->label('Conta Financeira para baixa')
                                    ->options(fn (): array => FinancialAccount::optionsForCompany(Filament::getTenant()?->id ?? 0))
                                    ->default(fn (): ?int => FinancialAccount::defaultIdForCompany(Filament::getTenant()?->id ?? 0))
                                    ->searchable()
                                    ->preload()
                                    ->native(false)
                                    ->visible(fn (Get $get): bool => (bool) $get('mark_as_received'))
                                    ->required(fn (Get $get): bool => (bool) $get('mark_as_received')),
                            ]),

                        Tab::make('NF-e')
                            ->visible(fn (Invoice $record): bool => self::hasProductItems($record))
                            ->schema([
                                Callout::make('Dados da NF-e')
                                    ->description('A NF-e será gerada automaticamente para os itens de produto da fatura, usando as regras fiscais cadastradas.')
                                    ->info(),
                            ]),

                        Tab::make('NFS-e')
                            ->visible(fn (Invoice $record): bool => self::hasServiceItems($record))
                            ->schema([
                                Select::make('nfse_service_id')
                                    ->label('Serviço do item da NFS-e')
                                    ->options(fn (Invoice $record): array => app(InvoiceService::class)->getNfseServiceOptions($record))
                                    ->default(function (Invoice $record): ?int {
                                        $serviceOptions = app(InvoiceService::class)->getNfseServiceOptions($record);

                                        return count($serviceOptions) === 1 ? (int) array_key_first($serviceOptions) : null;
                                    })
                                    ->native(false)
                                    ->searchable()
                                    ->live()
                                    ->required(fn (Invoice $record): bool => count(app(InvoiceService::class)->getNfseServiceOptions($record)) > 1)
                                    ->visible(fn (Invoice $record): bool => count(app(InvoiceService::class)->getNfseServiceOptions($record)) > 1)
                                    ->helperText('Quando houver mais de um serviço nas OS, escolha o serviço usado como base fiscal. A descrição pode ser ajustada abaixo.')
                                    ->afterStateUpdated(function ($state, callable $set, Invoice $record): void {
                                        $set(
                                            'nfse_item_description',
                                            app(InvoiceService::class)->buildNfseItemDescription(
                                                $record,
                                                selectedServiceId: filled($state) ? (int) $state : null
                                            )
                                        );
                                    }),

                                Textarea::make('nfse_item_description')
                                    ->label('Descrição do item da NFS-e')
                                    ->default(function (Invoice $record): string {
                                        $serviceOptions = app(InvoiceService::class)->getNfseServiceOptions($record);
                                        $defaultServiceId = count($serviceOptions) === 1 ? (int) array_key_first($serviceOptions) : null;

                                        return app(InvoiceService::class)->buildNfseItemDescription(
                                            $record,
                                            selectedServiceId: $defaultServiceId
                                        );
                                    })
                                    ->rows(4)
                                    ->helperText('Máximo de 2000 caracteres. Se a descrição automática ultrapassar esse limite, ela será cortada.')
                                    ->live(debounce: 300)
                                    ->afterStateUpdated(function (?string $state, callable $set): void {
                                        $set('nfse_item_description', mb_substr(trim((string) $state), 0, 2000));
                                    })
                                    ->maxLength(2000)
                                    ->required(),

                                Textarea::make('nfse_additional_information')
                                    ->label('Informações adicionais do item da NFS-e')
                                    ->default(fn (Invoice $record): string => app(InvoiceService::class)->buildNfseItemAdditionalInformation($record))
                                    ->rows(3)
                                    ->maxLength(500),
                            ]),
                    ]),
            ])
            ->action(function (Action $action, Invoice $record, array $data, EditInvoice $livewire): void {

                Log::info('ConfirmInvoiceAction UI: confirmando fatura - Invoice ID: '.$record->id, [
                    'metodo' => __METHOD__.'@'.__LINE__,
                    'invoice_id' => $record->id,
                    'data' => $data,
                ]);

                $service = app(InvoiceService::class);
                $result = $service->confirm($record, $data, Auth::id());

                if ($service->hasError() || $result === null) {
                    Log::error('ConfirmInvoiceAction UI: erro ao confirmar fatura', [
                        'metodo' => __METHOD__.'@'.__LINE__,
                        'invoice_id' => $record->id,
                        'message' => $service->getMessage(),
                        'error_code' => $service->getErrorCode(),
                        'errors' => $service->getErrors(),
                    ]);

                    notify::error(
                        message: $service->getMessageUser(),
                        errorCode: $service->getErrorCode()
                    );

                    $action->halt();

                    return;
                }

                $types = collect($result['documents_types'] ?? [])
                    ->map(static fn (string $type): string => strtoupper($type))
                    ->implode(', ');

                $paymentsCount = (int) ($result['payments_count'] ?? 0);

                $message = "Fatura confirmada com sucesso. {$result['documents_count']} documento(s) fiscal(is) ({$types}) e {$result['account_receivables_count']} conta(s) a receber geradas.";

                if ($paymentsCount > 0) {
                    $message .= " {$paymentsCount} pagamento(s) de contas a receber registrado(s) automaticamente.";
                }

                notify::success($message);

                if (($data['emit_fiscal_documents'] ?? false) === true) {
                    $emissionResult = self::emitGeneratedFiscalDocuments($record, Auth::id());

                    if ($emissionResult['failed'] === 0) {
                        notify::success(
                            "Emissão disparada com sucesso para {$emissionResult['sent']} documento(s) fiscal(is)."
                        );
                    } else {
                        $details = collect($emissionResult['errors'])
                            ->take(2)
                            ->implode(' | ');

                        notify::warning(
                            "A emissão foi disparada com pendências: {$emissionResult['sent']} documento(s) enviado(s) e {$emissionResult['failed']} com erro(s). {$details}"
                        );
                    }

                    $livewire->refreshInvoiceState();
                }

                $livewire->dispatch('invoice-confirmed');
            });
    }

    /**
     * @return array{sent:int, failed:int, errors:array<int, string>}
     */
    private static function emitGeneratedFiscalDocuments(Invoice $record, int $userId): array
    {
        $record->refresh()->loadMissing('fiscalDocuments');

        $sent = 0;
        $errors = [];

        foreach ($record->fiscalDocuments as $document) {
            $service = $document->isNfse()
                ? app(NfseDocumentService::class)
                : app(NfeDocumentService::class);

            $emitted = $service->emitir($document, $userId);

            if ($emitted) {
                $sent++;

                continue;
            }

            $message = $service->getMessageUser() ?: $service->getMessage() ?: 'Erro ao emitir documento fiscal.';

            $errors[] = sprintf(
                '%s #%d: %s',
                strtoupper((string) $document->document_type->value),
                $document->id,
                $message
            );

            Log::warning('ConfirmInvoiceAction UI: falha ao disparar emissão do documento fiscal', [
                'metodo' => __METHOD__.'@'.__LINE__,
                'invoice_id' => $record->id,
                'fiscal_document_id' => $document->id,
                'document_type' => $document->document_type->value,
                'message' => $message,
                'user_id' => $userId,
            ]);
        }

        return [
            'sent' => $sent,
            'failed' => count($errors),
            'errors' => $errors,
        ];
    }

    private static function hasProductItems(Invoice $record): bool
    {
        $record->loadMissing('requisitions.items');

        return $record->requisitions
            ->contains(fn ($requisition): bool => $requisition->items->isNotEmpty());
    }

    private static function hasServiceItems(Invoice $record): bool
    {
        $record->loadMissing('serviceOrders.items');

        return $record->serviceOrders
            ->contains(fn ($serviceOrder): bool => $serviceOrder->items->isNotEmpty());
    }

    private static function resolveDocumentTypesDescription(Invoice $record): string
    {
        $types = [];

        if (self::hasProductItems($record)) {
            $types[] = 'NF-e para itens de produto';
        }

        if (self::hasServiceItems($record)) {
            $types[] = 'NFS-e para itens de serviço';
        }

        if ($types === []) {
            return 'Nenhum item elegível encontrado nesta fatura.';
        }

        return implode(' e ', $types).'.';
    }
}
