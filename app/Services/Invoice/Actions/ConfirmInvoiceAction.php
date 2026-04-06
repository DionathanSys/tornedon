<?php

namespace App\Services\Invoice\Actions;

use App\Enum\FiscalDocument\BuyerPresenceIndicator;
use App\Enum\FiscalDocument\DocumentModel;
use App\Enum\FiscalDocument\FreightModality;
use App\Enum\FiscalDocument\IssuePurpose;
use App\Enum\FiscalDocument\NfseDescriptionMode;
use App\Enum\FiscalDocument\NfseModel;
use App\Enum\FiscalDocument\OperationNature;
use App\Enum\FiscalDocument\OperationType;
use App\Enum\Invoice\Status as InvoiceStatus;
use App\Enum\Payment\Condition as PaymentCondition;
use App\Enum\Payment\Method as PaymentMethod;
use App\Models\FiscalDocument;
use App\Models\Invoice;
use App\Services\AccountReceivable\AccountReceivableService;
use App\Services\Invoice\InvoiceService;
use App\Traits\HandlesActionResponse;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
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
            Log::debug('Iniciando confirmação de fatura', [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'invoice_id' => $this->invoice->id,
                'user_id'    => $this->confirmedBy,
                'data'       => $data,
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
            $paymentCondition = PaymentCondition::from((string) $data['payment_condition']);

            $this->invoice->update([
                'payment_method'    => $paymentMethod->value,
                'payment_condition' => $paymentCondition->value,
                'updated_by'        => $this->confirmedBy,
            ]);

            $this->invoice->refresh();

            $documentTypes = $this->resolveDocumentTypes();
            $generatedDocuments = [];

            $invoiceService = app(InvoiceService::class);

            foreach ($documentTypes as $documentType) {
                $fiscalDocument = $invoiceService->createFiscalDocument(
                    $this->invoice,
                    $this->buildFiscalData($documentType),
                    $this->confirmedBy
                );

                if ($invoiceService->hasError() || $fiscalDocument === null) {
                    $this->setError(
                        $invoiceService->getMessage(),
                        $invoiceService->getErrors(),
                        $invoiceService->getErrorCode()
                    );

                    Log::error('ConfirmInvoiceAction: falha ao gerar documento fiscal na confirmação da fatura', [
                        'metodo'        => __METHOD__ . '@' . __LINE__,
                        'invoice_id'    => $this->invoice->id,
                        'document_type' => $documentType->value,
                        'message'       => $invoiceService->getMessage(),
                        'error_code'    => $invoiceService->getErrorCode(),
                        'errors'        => $invoiceService->getErrors(),
                    ]);

                    return null;
                }

                $generatedDocuments[] = $fiscalDocument;
            }

            $createdReceivables = $this->createAccountReceivables(
                $paymentMethod,
                $paymentCondition,
                $generatedDocuments
            );

            if ($createdReceivables === null) {
                return null;
            }

            $this->invoice->update([
                'status'       => InvoiceStatus::CONFIRMED->value,
                'pending'      => false,
                'confirmed'    => true,
                'confirmed_at' => now(),
                'confirmed_by' => $this->confirmedBy,
                'updated_by'   => $this->confirmedBy,
            ]);

            $result = [
                'invoice_id' => $this->invoice->id,
                'documents_count' => count($generatedDocuments),
                'documents_types' => array_map(
                    static fn (FiscalDocument $document): string => $document->document_type->value,
                    $generatedDocuments
                ),
                'account_receivables_count' => count($createdReceivables),
            ];

            Log::info('Fatura confirmada com sucesso', [
                'metodo'                    => __METHOD__ . '@' . __LINE__,
                'invoice_id'                => $this->invoice->id,
                'documents_count'           => $result['documents_count'],
                'account_receivables_count' => $result['account_receivables_count'],
                'user_id'                   => $this->confirmedBy,
            ]);

            $this->setSuccess();

            return $result;
        } catch (Throwable $e) {
            $this->setError('Erro inesperado ao confirmar fatura');

            Log::error($this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'invoice_id' => $this->invoice->id,
                'message'    => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
                'error_code' => $this->getErrorCode(),
                'user_id'    => $this->confirmedBy,
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

    private function buildFiscalData(DocumentModel $documentType): array
    {
        $issueDate = ($this->invoice->invoice_date ?? now())->toDateString();

        if ($documentType === DocumentModel::NFSE) {
            $invoiceService = app(InvoiceService::class);

            return [
                'document_type' => DocumentModel::NFSE->value,
                'nfse_model' => NfseModel::MUNICIPAL->value,
                'issued_at' => $issueDate,
                'nfse_description_mode' => NfseDescriptionMode::AUTO->value,
                'nfse_item_description' => $invoiceService->buildNfseItemDescription(
                    $this->invoice,
                    NfseDescriptionMode::AUTO->value
                ),
            ];
        }

        return [
            'document_type' => DocumentModel::NFE->value,
            'operation_nature' => OperationNature::VENDA_DENTRO_ESTADO->value,
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
     * @param  array<int, FiscalDocument>  $generatedDocuments
     * @return array<int, mixed>|null
     */
    private function createAccountReceivables(
        PaymentMethod $paymentMethod,
        PaymentCondition $paymentCondition,
        array $generatedDocuments
    ): ?array {
        $installments = $this->buildInstallments($paymentCondition);

        if ($installments === []) {
            return null;
        }

        $service = app(AccountReceivableService::class);
        $created = [];
        $singleFiscalDocumentId = count($generatedDocuments) === 1 ? $generatedDocuments[0]->id : null;

        foreach ($installments as $installment) {
            $accountReceivable = $service->create([
                'customer_id' => $this->invoice->customer_id,
                'company_id' => $this->invoice->company_id,
                'invoice_id' => $this->invoice->id,
                'fiscal_document_id' => $singleFiscalDocumentId,
                'sequence_number' => $installment['sequence_number'],
                'due_date' => $installment['due_date'],
                'paid_date' => null,
                'due_amount' => $installment['due_amount'],
                'paid_amount' => 0,
                'document_number' => $this->invoice->invoice_number,
                'description' => sprintf(
                    'Parcela %d/%d gerada automaticamente na confirmação da fatura %s',
                    $installment['installment_number'],
                    $installment['installments_count'],
                    $this->invoice->invoice_number
                ),
                'paid' => false,
                'payment_method' => $paymentMethod->value,
            ], $this->confirmedBy);

            if ($service->hasError() || $accountReceivable === null) {
                $this->setError(
                    $service->getMessage(),
                    $service->getErrors(),
                    $service->getErrorCode()
                );

                Log::error('ConfirmInvoiceAction: falha ao gerar conta a receber na confirmação da fatura', [
                    'metodo'     => __METHOD__ . '@' . __LINE__,
                    'invoice_id' => $this->invoice->id,
                    'message'    => $service->getMessage(),
                    'error_code' => $service->getErrorCode(),
                    'errors'     => $service->getErrors(),
                    'payload'    => $installment,
                ]);

                return null;
            }

            $created[] = $accountReceivable;
        }

        return $created;
    }

    /**
     * @return array<int, array<string, int|float|string>>
     */
    private function buildInstallments(PaymentCondition $condition): array
    {
        $netValue = round((float) $this->invoice->netValue, 2);

        if ($netValue <= 0) {
            $this->setError('Valor líquido da fatura inválido para gerar contas a receber.');
            return [];
        }

        $totalCents = (int) round($netValue * 100);
        $installmentsCount = max(1, $condition->installments() ?: 1);
        $baseCents = intdiv($totalCents, $installmentsCount);
        $remainder = $totalCents - ($baseCents * $installmentsCount);
        $baseDate = Carbon::parse($this->invoice->invoice_date ?? now()->toDateString());
        $installments = [];

        for ($i = 1; $i <= $installmentsCount; $i++) {
            $amountCents = $baseCents + ($i === $installmentsCount ? $remainder : 0);

            $installments[] = [
                'sequence_number' => str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                'due_date' => $this->resolveDueDate($condition, $baseDate, $i)->toDateString(),
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

    private function resolveDueDate(PaymentCondition $condition, Carbon $baseDate, int $installmentNumber): Carbon
    {
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
}
