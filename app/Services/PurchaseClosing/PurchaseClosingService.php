<?php

namespace App\Services\PurchaseClosing;

use App\Enum\FiscalDocument\OperationType;
use App\Enum\PurchaseClosing\Status;
use App\Models\AccountPayable;
use App\Models\FiscalDocument;
use App\Models\PurchaseClosing;
use App\Models\PurchaseClosingFiscalDocument;
use App\Services\AccountPayable\AccountPayableService;
use App\Services\Audit\AuditRecorder;
use App\Services\PurchaseClosing\Validators\PurchaseClosingValidator;
use App\Traits\HandlesServiceResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class PurchaseClosingService
{
    use HandlesServiceResponse;

    public function __construct(
        private readonly AccountPayableService $accountPayableService = new AccountPayableService(),
    ) {}

    public function create(array $data, int $createdBy): ?PurchaseClosing
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($data, $createdBy) {
                $validated = PurchaseClosingValidator::validateCreate($data);
                $documents = $this->normalizeDocuments($validated['documents'] ?? []);
                $resolvedDocuments = $this->resolveDocuments($validated, $documents);
                $audit = app(AuditRecorder::class);

                $closing = PurchaseClosing::query()->create([
                    'company_id' => $validated['company_id'],
                    'supplier_id' => $validated['supplier_id'],
                    'start_date' => $validated['start_date'],
                    'end_date' => $validated['end_date'],
                    'reference' => $validated['reference'] ?? null,
                    'status' => Status::from((string) ($validated['status'] ?? Status::DRAFT->value))->value,
                    'notes' => $validated['notes'] ?? null,
                    'gross_amount' => 0,
                    'discount_amount' => 0,
                    'created_by' => $createdBy,
                    'updated_by' => $createdBy,
                ]);

                $this->syncDocuments($closing, $resolvedDocuments, $documents);
                $this->refreshAmounts($closing);

                $audit->recordModelEvent(
                    $closing->fresh(),
                    'purchase_closing.created',
                    'Fechamento de compra criado',
                    null,
                    $audit->snapshot($closing->fresh()),
                    $createdBy,
                );

                $this->setSuccess('Fechamento de compra criado com sucesso.');

                return $closing->fresh(['fiscalDocumentLinks', 'fiscalDocuments']);
            });
        } catch (ValidationException $e) {
            $this->setError('Falha de validação dos dados.', $e->errors(), 422);
        } catch (\Throwable $e) {
            $this->setError('Erro ao criar fechamento de compra.');

            Log::error($this->getMessage(), [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'data' => $data,
                'user_id' => $createdBy,
            ]);
        }

        return null;
    }

    public function update(PurchaseClosing $closing, array $data, int $updatedBy): ?PurchaseClosing
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($closing, $data, $updatedBy) {
                $this->ensureClosingEditable($closing);

                $validated = PurchaseClosingValidator::validateUpdate($data);
                $documents = $this->normalizeDocuments($validated['documents'] ?? []);
                $resolvedDocuments = $this->resolveDocuments($validated, $documents, $closing->id);
                $audit = app(AuditRecorder::class);
                $before = $audit->snapshot($closing);

                $closing->update([
                    'company_id' => $validated['company_id'],
                    'supplier_id' => $validated['supplier_id'],
                    'start_date' => $validated['start_date'],
                    'end_date' => $validated['end_date'],
                    'reference' => $validated['reference'] ?? null,
                    'status' => Status::from((string) ($validated['status'] ?? $closing->status?->value ?? Status::DRAFT->value))->value,
                    'notes' => $validated['notes'] ?? null,
                    'updated_by' => $updatedBy,
                ]);

                $this->syncDocuments($closing, $resolvedDocuments, $documents);
                $this->refreshAmounts($closing);

                $audit->recordModelEvent(
                    $closing->fresh(),
                    'purchase_closing.updated',
                    'Fechamento de compra atualizado',
                    $before,
                    $audit->snapshot($closing->fresh()),
                    $updatedBy,
                );

                $this->setSuccess('Fechamento de compra atualizado com sucesso.');

                return $closing->fresh(['fiscalDocumentLinks', 'fiscalDocuments']);
            });
        } catch (ValidationException $e) {
            $this->setError('Falha de validação dos dados.', $e->errors(), 422);
        } catch (\Throwable $e) {
            $this->setError($e instanceof \DomainException ? $e->getMessage() : 'Erro ao atualizar fechamento de compra.');

            if (! ($e instanceof \DomainException)) {
                Log::error($this->getMessage(), [
                    'metodo' => __METHOD__ . '@' . __LINE__,
                    'message' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'closing_id' => $closing->id,
                    'data' => $data,
                    'user_id' => $updatedBy,
                ]);
            }
        }

        return null;
    }

    public function generateAccountPayable(PurchaseClosing $closing, array $data, int $userId): ?AccountPayable
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($closing, $data, $userId) {
                $validated = PurchaseClosingValidator::validateGenerateAccountPayable($data);
                $closing->refresh();

                if ($closing->account_payable_id) {
                    throw new \DomainException('Este fechamento já possui uma conta a pagar vinculada.');
                }

                if ((float) $closing->net_amount <= 0) {
                    throw new \DomainException('O valor líquido do fechamento deve ser maior que zero.');
                }

                if (! $closing->fiscalDocumentLinks()->exists()) {
                    throw new \DomainException('O fechamento precisa possuir notas fiscais vinculadas.');
                }

                $payable = $this->accountPayableService->create([
                    'supplier_id' => $closing->supplier_id,
                    'company_id' => $closing->company_id,
                    'fiscal_document_id' => null,
                    'due_date' => $validated['due_date'],
                    'due_amount' => (float) $closing->net_amount,
                    'description' => $validated['description'] ?? $this->defaultPayableDescription($closing),
                    'document_number' => $validated['document_number'] ?? $closing->reference,
                    'payment_method' => $validated['payment_method'] ?? null,
                    'financial_category_id' => $validated['financial_category_id'] ?? null,
                    'cost_center_id' => $validated['cost_center_id'] ?? null,
                    'installment_count' => $validated['installment_count'] ?? 1,
                    'installment_due_mode' => $validated['installment_due_mode'] ?? null,
                    'installment_fixed_day' => $validated['installment_fixed_day'] ?? null,
                    'installment_interval_days' => $validated['installment_interval_days'] ?? null,
                    'amount_input_mode' => $validated['amount_input_mode'] ?? 'total',
                ], $userId);

                if ($this->accountPayableService->hasError() || ! $payable) {
                    $this->setError(
                        $this->accountPayableService->getMessage(),
                        $this->accountPayableService->getErrors(),
                        422,
                        $this->accountPayableService->getErrorCode()
                    );

                    return null;
                }

                $audit = app(AuditRecorder::class);
                $before = $audit->snapshot($closing);

                $closing->update([
                    'account_payable_id' => $payable->id,
                    'status' => Status::CLOSED->value,
                    'closed_at' => now(),
                    'closed_by' => $userId,
                    'updated_by' => $userId,
                ]);

                $audit->recordModelEvent(
                    $closing->fresh(),
                    'purchase_closing.account_payable_generated',
                    'Conta a pagar gerada a partir do fechamento',
                    $before,
                    $audit->snapshot($closing->fresh()),
                    $userId,
                    null,
                    ['account_payable_id' => $payable->id],
                );

                $this->setSuccess('Conta a pagar gerada com sucesso a partir do fechamento.');

                return $payable;
            });
        } catch (ValidationException $e) {
            $this->setError('Falha de validação dos dados.', $e->errors(), 422);
        } catch (\Throwable $e) {
            $this->setError($e instanceof \DomainException ? $e->getMessage() : 'Erro ao gerar conta a pagar do fechamento.');

            if (! ($e instanceof \DomainException)) {
                Log::error($this->getMessage(), [
                    'metodo' => __METHOD__ . '@' . __LINE__,
                    'message' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'closing_id' => $closing->id,
                    'data' => $data,
                    'user_id' => $userId,
                ]);
            }
        }

        return null;
    }

    public function reopen(PurchaseClosing $closing, int $userId): ?PurchaseClosing
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($closing, $userId) {
                $closing->refresh();

                if ($closing->account_payable_id) {
                    throw new \DomainException('Exclua a conta a pagar vinculada antes de reabrir o fechamento.');
                }

                $audit = app(AuditRecorder::class);
                $before = $audit->snapshot($closing);

                $closing->update([
                    'status' => Status::REOPENED->value,
                    'reopened_at' => now(),
                    'reopened_by' => $userId,
                    'updated_by' => $userId,
                ]);

                $audit->recordModelEvent(
                    $closing->fresh(),
                    'purchase_closing.reopened',
                    'Fechamento de compra reaberto',
                    $before,
                    $audit->snapshot($closing->fresh()),
                    $userId,
                );

                $this->setSuccess('Fechamento reaberto com sucesso.');

                return $closing->fresh();
            });
        } catch (\Throwable $e) {
            $this->setError($e instanceof \DomainException ? $e->getMessage() : 'Erro ao reabrir fechamento de compra.');

            if (! ($e instanceof \DomainException)) {
                Log::error($this->getMessage(), [
                    'metodo' => __METHOD__ . '@' . __LINE__,
                    'message' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'closing_id' => $closing->id,
                    'user_id' => $userId,
                ]);
            }
        }

        return null;
    }

    /**
     * @param  array<int, array{fiscal_document_id:int, discount_amount:float}>  $documents
     * @return array<int, array{fiscal_document_id:int, discount_amount:float}>
     */
    private function normalizeDocuments(array $documents): array
    {
        return array_map(static fn (array $document): array => [
            'fiscal_document_id' => (int) $document['fiscal_document_id'],
            'discount_amount' => round((float) ($document['discount_amount'] ?? 0), 2),
        ], $documents);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  array<int, array{fiscal_document_id:int, discount_amount:float}>  $documents
     * @return \Illuminate\Support\Collection<int, FiscalDocument>
     */
    private function resolveDocuments(array $validated, array $documents, ?int $ignoreClosingId = null)
    {
        $documentIds = array_column($documents, 'fiscal_document_id');

        $resolvedDocuments = FiscalDocument::query()
            ->with('items')
            ->whereIn('id', $documentIds)
            ->get()
            ->keyBy('id');

        foreach ($documents as $documentData) {
            /** @var FiscalDocument|null $document */
            $document = $resolvedDocuments->get($documentData['fiscal_document_id']);

            if (! $document) {
                throw ValidationException::withMessages([
                    'documents' => ['Uma das notas fiscais selecionadas não foi encontrada.'],
                ]);
            }

            if ((int) $document->company_id !== (int) $validated['company_id']) {
                throw ValidationException::withMessages([
                    'documents' => ["A nota #{$document->document_number} não pertence à empresa informada."],
                ]);
            }

            if ((int) $document->customer_id !== (int) $validated['supplier_id']) {
                throw ValidationException::withMessages([
                    'documents' => ["A nota #{$document->document_number} não pertence ao fornecedor informado."],
                ]);
            }

            if (! $document->confirmed) {
                throw ValidationException::withMessages([
                    'documents' => ["A nota #{$document->document_number} ainda não foi confirmada."],
                ]);
            }

            if ($document->operation_type !== OperationType::ENTRADA) {
                throw ValidationException::withMessages([
                    'documents' => ["A nota #{$document->document_number} não é uma nota de entrada."],
                ]);
            }

            $issuedAt = $document->issued_at?->toDateString();

            if (! $issuedAt || $issuedAt < $validated['start_date'] || $issuedAt > $validated['end_date']) {
                throw ValidationException::withMessages([
                    'documents' => ["A nota #{$document->document_number} está fora do período do fechamento."],
                ]);
            }

            $existingLink = PurchaseClosingFiscalDocument::query()
                ->where('fiscal_document_id', $document->id)
                ->when($ignoreClosingId, fn ($query) => $query->where('purchase_closing_id', '!=', $ignoreClosingId))
                ->first();

            if ($existingLink) {
                throw ValidationException::withMessages([
                    'documents' => ["A nota #{$document->document_number} já pertence a outro fechamento."],
                ]);
            }

            $documentAmount = round((float) $document->items->sum(fn ($item) => (float) $item->total_price), 2);
            if ($documentData['discount_amount'] > $documentAmount) {
                throw ValidationException::withMessages([
                    'documents' => ["O desconto da nota #{$document->document_number} não pode ser maior que o valor da nota."],
                ]);
            }
        }

        return $resolvedDocuments;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, FiscalDocument>  $resolvedDocuments
     * @param  array<int, array{fiscal_document_id:int, discount_amount:float}>  $documents
     */
    private function syncDocuments(PurchaseClosing $closing, $resolvedDocuments, array $documents): void
    {
        $closing->fiscalDocumentLinks()->delete();

        foreach ($documents as $documentData) {
            /** @var FiscalDocument $document */
            $document = $resolvedDocuments->get($documentData['fiscal_document_id']);

            $closing->fiscalDocumentLinks()->create([
                'fiscal_document_id' => $document->id,
                'document_amount' => round((float) $document->items->sum(fn ($item) => (float) $item->total_price), 2),
                'discount_amount' => $documentData['discount_amount'],
            ]);
        }
    }

    private function refreshAmounts(PurchaseClosing $closing): void
    {
        $closing->load('fiscalDocumentLinks');
        $grossAmount = round((float) $closing->fiscalDocumentLinks->sum(fn (PurchaseClosingFiscalDocument $link) => (float) $link->document_amount), 2);
        $discountAmount = round((float) $closing->fiscalDocumentLinks->sum(fn (PurchaseClosingFiscalDocument $link) => (float) $link->discount_amount), 2);

        $closing->update([
            'gross_amount' => $grossAmount,
            'discount_amount' => $discountAmount,
        ]);
    }

    private function ensureClosingEditable(PurchaseClosing $closing): void
    {
        if ($closing->account_payable_id) {
            throw new \DomainException('Não é possível alterar um fechamento com conta a pagar vinculada.');
        }
    }

    private function defaultPayableDescription(PurchaseClosing $closing): string
    {
        $period = $closing->start_date->format('d/m/Y') . ' a ' . $closing->end_date->format('d/m/Y');

        return 'Fechamento de compras ' . trim(($closing->reference ? $closing->reference . ' - ' : '') . $period);
    }
}
