<?php

namespace App\Services\Invoice\Actions;

use App\Enum\FiscalDocument\BuyerPresenceIndicator;
use App\Enum\FiscalDocument\DocumentModel;
use App\Enum\FiscalDocument\FreightModality;
use App\Enum\FiscalDocument\IssuePurpose;
use App\Enum\FiscalDocument\OperationNature;
use App\Enum\FiscalDocument\OperationType;
use App\Enum\Invoice\Status as InvoiceStatus;
use App\Enum\Payment\Condition as PaymentCondition;
use App\Enum\Payment\Method as PaymentMethod;
use App\Models\AccountReceivable;
use App\Models\CardPaymentProfile;
use App\Models\CompanyPreference;
use App\Models\FiscalDocument;
use App\Models\Invoice;
use App\Services\AccountReceivable\AccountReceivableService;
use App\Services\Audit\AuditRecorder;
use App\Services\Fiscal\NfseConfigService;
use App\Services\Invoice\InvoiceService;
use App\Support\Financial\InstallmentSchedule;
use App\Traits\HandlesActionResponse;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ConfirmInvoiceAction
{
    use HandlesActionResponse;

    public function __construct(
        private Invoice $invoice,
        private int $confirmedBy,
    ) {}

    public function execute(array $data): ?array
    {
        try {
            $audit = app(AuditRecorder::class);
            $before = $audit->snapshot($this->invoice);

            Log::debug('Iniciando confirmacao de fatura - Invoice ID: '.$this->invoice->id, [
                'metodo' => __METHOD__.'@'.__LINE__,
                'invoice_id' => $this->invoice->id,
                'user_id' => $this->confirmedBy,
                'data' => $data,
            ]);

            $this->invoice->loadMissing([
                'requisitions.items.product.tax',
                'serviceOrders.items.service',
                'company.fiscalProfile',
                'customer',
                'fiscalDocuments',
                'accountReceivables',
            ]);

            if (! $this->validateCanConfirm()) {
                return null;
            }

            $paymentMethod = PaymentMethod::from((string) $data['payment_method']);
            $paymentCondition = $this->resolvePaymentCondition($paymentMethod, $data);

            if ($paymentCondition === false) {
                return null;
            }

            $this->invoice->update([
                'payment_method' => $paymentMethod->value,
                'payment_condition' => $paymentCondition?->value,
                'financial_category_id' => $this->resolveFinancialCategoryId($data),
                'updated_by' => $this->confirmedBy,
            ]);

            $this->invoice->refresh();

            $documentTypes = $this->resolveDocumentTypes();
            $generatedDocuments = [];

            $invoiceService = app(InvoiceService::class);

            foreach ($documentTypes as $documentType) {
                $fiscalDocument = $invoiceService->createFiscalDocument(
                    $this->invoice,
                    $this->buildFiscalData($documentType, $data),
                    $this->confirmedBy
                );

                if ($invoiceService->hasError() || $fiscalDocument === null) {
                    $this->setError(
                        $invoiceService->getMessage(),
                        $invoiceService->getErrors(),
                        $invoiceService->getErrorCode()
                    );

                    Log::error('ConfirmInvoiceAction: falha ao gerar documento fiscal na confirmação da fatura', [
                        'metodo' => __METHOD__.'@'.__LINE__,
                        'invoice_id' => $this->invoice->id,
                        'document_type' => $documentType->value,
                        'message' => $invoiceService->getMessage(),
                        'error_code' => $invoiceService->getErrorCode(),
                        'errors' => $invoiceService->getErrors(),
                    ]);

                    return null;
                }

                $generatedDocuments[] = $fiscalDocument;
            }

            $createdReceivables = $this->createAccountReceivables(
                $paymentMethod,
                $paymentCondition,
                $data,
            );

            if ($createdReceivables === null) {
                return null;
            }

            $paymentsCount = $this->registerReceivablePaymentsIfNeeded($createdReceivables, $data);

            if ($paymentsCount === null) {
                return null;
            }

            $this->invoice->update([
                'status' => InvoiceStatus::CONFIRMED->value,
                'pending' => false,
                'confirmed' => true,
                'confirmed_at' => now(),
                'confirmed_by' => $this->confirmedBy,
                'updated_by' => $this->confirmedBy,
            ]);
            $this->invoice->refresh();

            $result = [
                'invoice_id' => $this->invoice->id,
                'documents_count' => count($generatedDocuments),
                'documents_types' => array_map(
                    static fn (FiscalDocument $document): string => $document->document_type->value,
                    $generatedDocuments
                ),
                'account_receivables_count' => count($createdReceivables),
                'payments_count' => $paymentsCount,
            ];

            $audit->recordModelEvent(
                $this->invoice,
                'invoice.confirmed',
                "Fatura #{$this->invoice->invoice_number} confirmada",
                $before,
                $audit->snapshot($this->invoice),
                $this->confirmedBy,
                null,
                [
                    'documents_count' => $result['documents_count'],
                    'documents_types' => $result['documents_types'],
                    'account_receivables_count' => $result['account_receivables_count'],
                    'payments_count' => $result['payments_count'],
                ],
            );

            Log::info('Fatura confirmada com sucesso', [
                'metodo' => __METHOD__.'@'.__LINE__,
                'invoice_id' => $this->invoice->id,
                'documents_count' => $result['documents_count'],
                'account_receivables_count' => $result['account_receivables_count'],
                'payments_count' => $result['payments_count'],
                'user_id' => $this->confirmedBy,
            ]);

            $this->setSuccess();

            return $result;
        } catch (Throwable $e) {
            $this->setError('Erro inesperado ao confirmar fatura');

            Log::error($this->getMessage(), [
                'metodo' => __METHOD__.'@'.__LINE__,
                'invoice_id' => $this->invoice->id,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'error_code' => $this->getErrorCode(),
                'user_id' => $this->confirmedBy,
            ]);

            return null;
        }
    }

    private function validateCanConfirm(): bool
    {
        if ($this->invoice->canceled || $this->invoice->status === InvoiceStatus::CANCELLED) {
            $this->setError('Não é possível confirmar uma fatura cancelada.');

            return false;
        }

        if ($this->invoice->confirmed || $this->invoice->status === InvoiceStatus::CONFIRMED) {
            $this->setError('Esta fatura já foi confirmada.');

            return false;
        }

        if ($this->invoice->fiscalDocuments->isNotEmpty()) {
            $this->setError('Esta fatura já possui documento fiscal gerado.');

            return false;
        }

        if ($this->invoice->accountReceivables->isNotEmpty()) {
            $this->setError('Esta fatura já possui contas a receber vinculadas.');

            return false;
        }

        if ($this->invoice->requisitions->isEmpty() && $this->invoice->serviceOrders->isEmpty()) {
            $this->setError('A fatura não possui itens vinculados para confirmação.');

            return false;
        }

        if (! $this->hasProductItems() && ! $this->hasServiceItems()) {
            $this->setError('A fatura não possui itens válidos para gerar documento fiscal.');

            return false;
        }

        return true;
    }

    /**
     * @return array<int, DocumentModel>
     */
    private function resolveDocumentTypes(): array
    {
        $types = [];

        if ($this->hasProductItems()) {
            $types[] = DocumentModel::NFE;
        }

        if ($this->hasServiceItems()) {
            $types[] = DocumentModel::NFSE;
        }

        return $types;
    }

    private function hasProductItems(): bool
    {
        return $this->invoice->requisitions
            ->contains(fn ($requisition): bool => $requisition->items->isNotEmpty());
    }

    private function hasServiceItems(): bool
    {
        return $this->invoice->serviceOrders
            ->contains(fn ($serviceOrder): bool => $serviceOrder->items->isNotEmpty());
    }

    private function buildFiscalData(DocumentModel $documentType, array $data): array
    {
        $issueDate = ($this->invoice->invoice_date ?? now())->toDateString();

        if ($documentType === DocumentModel::NFSE) {
            $invoiceService = app(InvoiceService::class);
            $nfseModel = app(NfseConfigService::class)
                ->resolveNfseModeloPadrao((int) $this->invoice->company_id);

            $selectedServiceId = isset($data['nfse_service_id']) ? (int) $data['nfse_service_id'] : null;
            $description = trim((string) ($data['nfse_item_description'] ?? ''));
            $additionalInformation = trim((string) ($data['nfse_additional_information'] ?? ''));

            return [
                'document_type' => DocumentModel::NFSE->value,
                'nfse_model' => $nfseModel->value,
                'issued_at' => $issueDate,
                'nfse_service_id' => $selectedServiceId,
                'nfse_item_description' => $description !== ''
                    ? $description
                    : $invoiceService->buildNfseItemDescription($this->invoice, selectedServiceId: $selectedServiceId),
                'nfse_additional_information' => $additionalInformation !== ''
                    ? $additionalInformation
                    : $invoiceService->buildNfseItemAdditionalInformation($this->invoice),
            ];
        }

        $operationNature = $this->resolveOperationNature();

        return [
            'document_type' => DocumentModel::NFE->value,
            'operation_nature' => $operationNature->value,
            'operation_type' => OperationType::SAIDA->value,
            'issue_purpose' => IssuePurpose::NORMAL->value,
            'is_final_consumer' => true,
            'buyer_presence_indicator' => BuyerPresenceIndicator::PRESENCIAL->value,
            'issued_at' => $issueDate,
            'movement_at' => $issueDate,
            'freight_data' => [
                'modalidade_frete' => FreightModality::SEM_FRETE->value,
            ],
        ];
    }

    /**
     * Resolve a natureza da operação com base no endereço da empresa e do cliente.
     *
     * Compara a UF do emitente (empresa) com a UF do destinatário (cliente):
     *   - Mesma UF       → VENDA DENTRO DO ESTADO
     *   - UFs diferentes → VENDA FORA DO ESTADO
     */
    private function resolveOperationNature(): OperationNature
    {
        $companyUf = mb_strtoupper(trim(
            $this->invoice->company->address['state'] ?? ''
        ));

        $customerUf = mb_strtoupper(trim(
            $this->invoice->customer?->address?->first()?->state ?? ''
        ));

        Log::debug('ConfirmInvoiceAction: resolveOperationNature', [
            'invoice_id' => $this->invoice->id,
            'company_uf' => $companyUf,
            'customer_uf' => $customerUf,
        ]);

        if ($companyUf !== '' && $customerUf !== '' && $companyUf !== $customerUf) {
            Log::info('ConfirmInvoiceAction: Operação interestadual detectada', [
                'invoice_id' => $this->invoice->id,
                'company_uf' => $companyUf,
                'customer_uf' => $customerUf,
            ]);

            return OperationNature::VENDA_FORA_ESTADO;
        }

        return OperationNature::VENDA_DENTRO_ESTADO;
    }

    /**
     * @return array<int, mixed>|null
     */
    private function createAccountReceivables(
        PaymentMethod $paymentMethod,
        ?PaymentCondition $paymentCondition,
        array $data,
    ): ?array {
        $installments = $this->buildInstallments($paymentMethod, $paymentCondition, $data);

        if ($installments === []) {
            return null;
        }

        $service = app(AccountReceivableService::class);

        if (
            $paymentMethod === PaymentMethod::CREDIT_CARD
            && blank($data['card_payment_profile_id'] ?? null)
        ) {
            $this->setError('Selecione o perfil de recebimento para confirmar a fatura em cartão de crédito.');

            return null;
        }

        if (
            $paymentMethod === PaymentMethod::CREDIT_CARD
            && blank($data['payment_date'] ?? null)
        ) {
            $this->setError('Informe a data da venda/pagamento para confirmar a fatura em cartão de crédito.');

            return null;
        }

        $accountReceivable = $service->create([
            'customer_id' => $this->invoice->customer_id,
            'company_id' => $this->invoice->company_id,
            'invoice_id' => $this->invoice->id,
            'fiscal_document_id' => null,
            'sequence_number' => '01',
            'due_date' => $installments[0]['due_date'],
            'paid_date' => null,
            'due_amount' => round((float) $this->invoice->netValue, 2),
            'paid_amount' => 0,
            'document_number' => Str::padLeft($this->invoice->invoice_number, 5, '0'),
            'description' => sprintf(
                'Referente à fatura %s',
                Str::padLeft($this->invoice->invoice_number, 5, '0')
            ),
            'paid' => false,
            'payment_method' => $paymentMethod->value,
            'card_payment_profile_id' => $paymentMethod === PaymentMethod::CREDIT_CARD
                ? (int) ($data['card_payment_profile_id'] ?? 0)
                : null,
            'payment_date' => $paymentMethod === PaymentMethod::CREDIT_CARD
                ? (string) ($data['payment_date'] ?? $this->invoice->invoice_date?->toDateString() ?? now()->toDateString())
                : null,
            'financial_category_id' => $this->invoice->financial_category_id,
            'installment_count' => count($installments),
            'installment_due_mode' => InstallmentSchedule::CUSTOM_INTERVAL_DAYS,
            'installment_interval_days' => 30,
        ], $this->confirmedBy);

        if ($service->hasError() || $accountReceivable === null) {
            $this->setError(
                $service->getMessage(),
                $service->getErrors(),
                $service->getErrorCode()
            );

            Log::error('ConfirmInvoiceAction: falha ao gerar conta a receber na confirmacao da fatura', [
                'metodo' => __METHOD__.'@'.__LINE__,
                'invoice_id' => $this->invoice->id,
                'message' => $service->getMessage(),
                'error_code' => $service->getErrorCode(),
                'errors' => $service->getErrors(),
                'payload' => $installments,
            ]);

            return null;
        }

        return [$accountReceivable];
    }

    /**
     * @param  array<int, AccountReceivable>  $accountReceivables
     */
    private function registerReceivablePaymentsIfNeeded(array $accountReceivables, array $data): ?int
    {
        if (($data['mark_as_received'] ?? false) !== true) {
            return 0;
        }

        $financialAccountId = $data['financial_account_id'] ?? null;

        if (! $financialAccountId) {
            $this->setError('Selecione uma conta financeira para registrar o recebimento automático.');

            return null;
        }

        $paymentDate = (string) ($data['received_at'] ?? now()->toDateString());
        $service = app(AccountReceivableService::class);
        $paymentsCount = 0;

        foreach ($accountReceivables as $accountReceivable) {
            $accountReceivable->loadMissing('installments');

            foreach ($accountReceivable->installments as $installment) {
                $amount = round((float) $installment->balance_amount, 2);

                if ($amount <= 0) {
                    continue;
                }

                $payment = $service->registerInstallmentPayment(
                    $installment,
                    $amount,
                    $paymentDate,
                    [
                        'financial_account_id' => (int) $financialAccountId,
                        'description' => sprintf(
                            'Recebimento automático na confirmação da fatura %s',
                            Str::padLeft($this->invoice->invoice_number, 5, '0')
                        ),
                        'user_id' => $this->confirmedBy,
                    ]
                );

                if ($service->hasError() || $payment === null) {
                    $this->setError(
                        $service->getMessage(),
                        $service->getErrors(),
                        422,
                        $service->getErrorCode()
                    );

                    Log::error('ConfirmInvoiceAction: falha ao registrar recebimento automático da parcela', [
                        'metodo' => __METHOD__.'@'.__LINE__,
                        'invoice_id' => $this->invoice->id,
                        'account_receivable_id' => $accountReceivable->id,
                        'installment_id' => $installment->id,
                        'amount' => $amount,
                        'payment_date' => $paymentDate,
                        'financial_account_id' => $financialAccountId,
                        'message' => $service->getMessage(),
                        'error_code' => $service->getErrorCode(),
                        'errors' => $service->getErrors(),
                    ]);

                    return null;
                }

                $paymentsCount++;
            }
        }

        return $paymentsCount;
    }

    /**
     * @return array<int, array<string, int|float|string>>
     */
    private function buildInstallments(PaymentMethod $paymentMethod, ?PaymentCondition $condition, array $data): array
    {
        $netValue = round((float) $this->invoice->netValue, 2);

        if ($netValue <= 0) {
            $this->setError('Valor líquido da fatura inválido para gerar contas a receber.');

            return [];
        }

        if ($paymentMethod !== PaymentMethod::CREDIT_CARD && $condition === null) {
            $this->setError('Condicao de pagamento obrigatoria para gerar contas a receber desta fatura.');

            return [];
        }

        $totalCents = (int) round($netValue * 100);
        $installmentsCount = max(1, $condition?->installments() ?: 1);
        $baseCents = intdiv($totalCents, $installmentsCount);
        $remainder = $totalCents - ($baseCents * $installmentsCount);
        $baseDate = Carbon::parse($this->invoice->invoice_date ?? now()->toDateString());
        $cardPaymentDate = $paymentMethod === PaymentMethod::CREDIT_CARD
            ? Carbon::parse((string) ($data['payment_date'] ?? $this->invoice->invoice_date?->toDateString() ?? now()->toDateString()))
            : null;
        $cardPaymentProfile = $paymentMethod === PaymentMethod::CREDIT_CARD
            ? $this->resolveCardProfile((int) ($data['card_payment_profile_id'] ?? 0))
            : null;

        if ($paymentMethod === PaymentMethod::CREDIT_CARD && $cardPaymentProfile === null) {
            return [];
        }

        $installments = [];

        for ($i = 1; $i <= $installmentsCount; $i++) {
            $amountCents = $baseCents + ($i === $installmentsCount ? $remainder : 0);

            $installments[] = [
                'sequence_number' => str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                'due_date' => $this->resolveDueDate(
                    $paymentMethod,
                    $condition,
                    $baseDate,
                    $i,
                    $cardPaymentDate,
                    $cardPaymentProfile,
                )->toDateString(),
                'due_amount' => round($amountCents / 100, 2),
                'installment_number' => $i,
                'installments_count' => $installmentsCount,
            ];
        }

        $sum = round(array_sum(array_column($installments, 'due_amount')), 2);

        if ($sum !== $netValue) {
            $this->setError('Falha de integridade financeira ao gerar contas a receber da fatura.');

            return [];
        }

        return $installments;
    }

    private function resolveDueDate(
        PaymentMethod $paymentMethod,
        ?PaymentCondition $condition,
        Carbon $baseDate,
        int $installmentNumber,
        ?Carbon $cardPaymentDate,
        ?CardPaymentProfile $cardPaymentProfile,
    ): Carbon {
        if ($paymentMethod === PaymentMethod::CREDIT_CARD && $cardPaymentDate && $cardPaymentProfile) {
            $firstDueDate = $cardPaymentDate->copy()->addDays((int) $cardPaymentProfile->settlement_days);

            return $installmentNumber === 1
                ? $firstDueDate
                : $firstDueDate->copy()->addDays(30 * ($installmentNumber - 1));
        }

        if ($condition === null) {
            return $baseDate->copy();
        }

        if ($condition->isCash() || $condition === PaymentCondition::CUSTOM) {
            return $baseDate->copy();
        }

        if ($condition->installments() > 1) {
            $daysStep = max($condition->days(), 30);

            return $baseDate->copy()->addDays($daysStep * $installmentNumber);
        }

        if ($condition->isTerm()) {
            return $baseDate->copy()->addDays($condition->days());
        }

        return $baseDate->copy();
    }

    private function resolveFinancialCategoryId(array $data): ?int
    {
        $categoryId = $data['financial_category_id']
            ?? $this->invoice->financial_category_id
            ?? CompanyPreference::getDefaultReceivableFinancialCategoryId($this->invoice->company_id);

        return filled($categoryId) ? (int) $categoryId : null;
    }

    private function resolveCardProfile(int $profileId): ?CardPaymentProfile
    {
        if ($profileId <= 0) {
            $this->setError('Selecione o perfil de recebimento para confirmar a fatura em cartao de credito.');

            return null;
        }

        $profile = CardPaymentProfile::query()
            ->where('company_id', $this->invoice->company_id)
            ->where('active', true)
            ->find($profileId);

        if (! $profile) {
            $this->setError('Perfil de cartao invalido para a empresa da fatura.');

            return null;
        }

        return $profile;
    }

    private function resolvePaymentCondition(PaymentMethod $paymentMethod, array $data): PaymentCondition|false|null
    {
        $rawCondition = $data['payment_condition'] ?? null;

        if (blank($rawCondition)) {
            if ($paymentMethod === PaymentMethod::CREDIT_CARD) {
                return null;
            }

            $this->setError('Condicao de pagamento obrigatoria para confirmar a fatura.');

            return false;
        }

        return PaymentCondition::from((string) $rawCondition);
    }
}
