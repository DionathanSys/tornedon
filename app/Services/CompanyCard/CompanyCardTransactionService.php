<?php

namespace App\Services\CompanyCard;

use App\Models\CompanyCardTransaction;
use App\Models\CompanyCreditCard;
use App\Models\FiscalDocument;
use App\Models\FinancialCategory;
use App\Models\Partner;
use App\Models\PurchaseClosingFiscalDocument;
use App\Services\Audit\AuditRecorder;
use App\Traits\HandlesServiceResponse;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CompanyCardTransactionService
{
    use HandlesServiceResponse;

    public function __construct(
        private readonly CompanyCardStatementService $statementService = new CompanyCardStatementService(),
    ) {}

    /**
     * @param array<string, mixed> $data
     * @return array<int, CompanyCardTransaction>|null
     */
    public function createManual(array $data, int $userId): ?array
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($data, $userId) {
                $validated = $this->validateTransactionPayload($data, requireSource: false);

                $transactions = $this->persistInstallments(
                    card: $validated['card'],
                    companyId: (int) $validated['company_id'],
                    transactionDate: Carbon::parse((string) $validated['transaction_date']),
                    postingDate: isset($validated['posting_date']) ? Carbon::parse((string) $validated['posting_date']) : null,
                    description: (string) $validated['description'],
                    vendorId: $validated['vendor_id'] ?? null,
                    amount: (float) $validated['amount'],
                    installments: (int) ($validated['installments'] ?? 1),
                    categoryId: $validated['category_id'] ?? null,
                    costCenterId: $validated['cost_center_id'] ?? null,
                    sourceType: $validated['source_type'] ?? 'manual_purchase',
                    sourceId: $validated['source_id'] ?? null,
                    sourceDescription: $validated['source_description'] ?? null,
                    meta: $validated['meta'] ?? null,
                );

                $this->auditCreation($transactions, $userId, 'company_card_transaction.created');

                $this->setSuccess('Transação de cartão corporativo registrada com sucesso.');

                return $transactions;
            });
        } catch (ValidationException $e) {
            $this->setError('Falha de validação dos dados.', $e->errors(), 422);
            return null;
        } catch (\Throwable $e) {
            $this->setError('Erro ao registrar transação de cartão corporativo.');
            return null;
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return array<int, CompanyCardTransaction>|null
     */
    public function createFromFiscalDocument(FiscalDocument $document, array $data, int $userId): ?array
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($document, $data, $userId) {
                $payload = [
                    ...$data,
                    'company_id' => $document->company_id,
                    'vendor_id' => $data['vendor_id'] ?? $document->customer_id,
                    'source_type' => 'fiscal_document',
                    'source_id' => $document->id,
                    'source_description' => $data['source_description'] ?? trim((string) ($document->document_number ?? $document->document_key ?? 'Documento fiscal')), 
                    'transaction_date' => $data['transaction_date']
                        ?? $document->movement_at?->toDateString()
                        ?? $document->issued_at?->toDateString()
                        ?? now()->toDateString(),
                ];

                $validated = $this->validateTransactionPayload($payload, requireSource: true);
                $installments = (int) ($validated['installments'] ?? 1);

                $existsInPurchaseClosing = PurchaseClosingFiscalDocument::query()
                    ->where('fiscal_document_id', (int) $document->id)
                    ->exists();

                if ($existsInPurchaseClosing) {
                    throw ValidationException::withMessages([
                        'source_id' => ['Este documento fiscal já está vinculado a um fechamento de compras e não pode ser lançado no cartão corporativo.'],
                    ]);
                }

                $alreadyExists = CompanyCardTransaction::query()
                    ->where('company_id', (int) $document->company_id)
                    ->where('source_type', 'fiscal_document')
                    ->where('source_id', (int) $document->id)
                    ->where('installments', $installments)
                    ->count();

                if ($alreadyExists >= $installments) {
                    throw ValidationException::withMessages([
                        'source_id' => ['Já existem transações de cartão registradas para este documento fiscal.'],
                    ]);
                }

                $transactions = $this->persistInstallments(
                    card: $validated['card'],
                    companyId: (int) $validated['company_id'],
                    transactionDate: Carbon::parse((string) $validated['transaction_date']),
                    postingDate: isset($validated['posting_date']) ? Carbon::parse((string) $validated['posting_date']) : null,
                    description: (string) $validated['description'],
                    vendorId: $validated['vendor_id'] ?? null,
                    amount: (float) $validated['amount'],
                    installments: $installments,
                    categoryId: $validated['category_id'] ?? null,
                    costCenterId: $validated['cost_center_id'] ?? null,
                    sourceType: 'fiscal_document',
                    sourceId: (int) $document->id,
                    sourceDescription: $validated['source_description'] ?? null,
                    meta: [
                        ...(array) ($validated['meta'] ?? []),
                        'fiscal_document_id' => $document->id,
                        'fiscal_document_number' => $document->document_number,
                    ],
                );

                $this->auditCreation($transactions, $userId, 'company_card_transaction.created_from_fiscal_document');

                $this->setSuccess('Transação de cartão corporativo criada a partir do documento fiscal.');

                return $transactions;
            });
        } catch (ValidationException $e) {
            $this->setError('Falha de validação dos dados.', $e->errors(), 422);
            return null;
        } catch (\Throwable $e) {
            $this->setError('Erro ao criar transação de cartão a partir do documento fiscal.');
            return null;
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function validateTransactionPayload(array $data, bool $requireSource): array
    {
        $rules = [
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'company_credit_card_id' => ['required', 'integer', 'exists:company_credit_cards,id'],
            'transaction_date' => ['required', 'date'],
            'posting_date' => ['nullable', 'date'],
            'description' => ['required', 'string', 'max:255'],
            'vendor_id' => ['nullable', 'integer', 'exists:partners,id'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'installments' => ['nullable', 'integer', 'min:1', 'max:120'],
            'category_id' => ['nullable', 'integer', 'exists:financial_categories,id'],
            'cost_center_id' => ['nullable', 'integer'],
            'source_type' => [$requireSource ? 'required' : 'nullable', 'string', 'max:80'],
            'source_id' => [$requireSource ? 'required' : 'nullable', 'integer', 'min:1'],
            'source_description' => ['nullable', 'string', 'max:255'],
            'meta' => ['nullable', 'array'],
        ];

        $validated = Validator::make($data, $rules)->validate();

        $card = CompanyCreditCard::query()->find((int) $validated['company_credit_card_id']);
        if (! $card || (int) $card->company_id !== (int) $validated['company_id']) {
            throw ValidationException::withMessages([
                'company_credit_card_id' => ['Cartão corporativo não pertence à empresa informada.'],
            ]);
        }

        if (! $card->active) {
            throw ValidationException::withMessages([
                'company_credit_card_id' => ['O cartão corporativo selecionado está inativo.'],
            ]);
        }

        if (isset($validated['vendor_id'])) {
            $partnerExists = Partner::query()->whereKey((int) $validated['vendor_id'])->exists();
            if (! $partnerExists) {
                throw ValidationException::withMessages([
                    'vendor_id' => ['Fornecedor não encontrado.'],
                ]);
            }
        }

        if (isset($validated['category_id'])) {
            $category = FinancialCategory::query()->find((int) $validated['category_id']);
            if (! $category || (int) $category->company_id !== (int) $validated['company_id']) {
                throw ValidationException::withMessages([
                    'category_id' => ['Categoria financeira inválida para a empresa informada.'],
                ]);
            }
        }

        $validated['card'] = $card;

        return $validated;
    }

    /**
     * @param array<string, mixed>|null $meta
     * @return array<int, CompanyCardTransaction>
     */
    private function persistInstallments(
        CompanyCreditCard $card,
        int $companyId,
        Carbon $transactionDate,
        ?Carbon $postingDate,
        string $description,
        ?int $vendorId,
        float $amount,
        int $installments,
        ?int $categoryId,
        ?int $costCenterId,
        string $sourceType,
        ?int $sourceId,
        ?string $sourceDescription,
        ?array $meta,
    ): array {
        $amountInCents = (int) round($amount * 100);
        $baseInstallment = intdiv($amountInCents, $installments);
        $remainder = $amountInCents - ($baseInstallment * $installments);
        $groupUuid = $installments > 1 ? (string) \Illuminate\Support\Str::uuid() : null;
        $parentId = null;
        $result = [];

        for ($index = 0; $index < $installments; $index++) {
            $installmentAmount = ($baseInstallment + ($index === $installments - 1 ? $remainder : 0)) / 100;
            $installmentDate = $transactionDate->copy()->addMonthsNoOverflow($index);
            $referenceMonth = $this->statementService
                ->resolveReferenceMonth($card, $installmentDate)
                ->startOfMonth()
                ->toDateString();

            $transaction = CompanyCardTransaction::query()->create([
                'company_id' => $companyId,
                'company_credit_card_id' => $card->id,
                'transaction_date' => $installmentDate->toDateString(),
                'posting_date' => $postingDate?->toDateString(),
                'description' => $description,
                'vendor_id' => $vendorId,
                'amount' => round($installmentAmount, 2),
                'installments' => $installments,
                'current_installment' => $index + 1,
                'installment_group_uuid' => $groupUuid,
                'parent_transaction_id' => $parentId,
                'category_id' => $categoryId,
                'cost_center_id' => $costCenterId,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'source_description' => $sourceDescription,
                'statement_reference_month' => $referenceMonth,
                'status' => 'posted',
                'meta' => $meta,
            ]);

            if ($parentId === null) {
                $parentId = $transaction->id;
                if ($installments > 1) {
                    $transaction->update(['parent_transaction_id' => $parentId]);
                }
            }

            $result[] = $transaction->fresh();
        }

        return $result;
    }

    /**
     * @param array<int, CompanyCardTransaction> $transactions
     */
    private function auditCreation(array $transactions, int $userId, string $event): void
    {
        $audit = app(AuditRecorder::class);

        foreach ($transactions as $transaction) {
            $audit->recordModelEvent(
                $transaction,
                $event,
                'Transação de cartão corporativo criada',
                null,
                $audit->snapshot($transaction),
                $userId,
            );
        }
    }
}
