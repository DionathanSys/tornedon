<?php

namespace App\Services\Financial;

use App\Enum\Financial\CashMovementDirection;
use App\Models\AccountPayableInstallmentPayment;
use App\Models\AccountReceivableInstallmentPayment;
use App\Models\CashMovement;
use App\Models\FinancialAccount;
use App\Traits\HandlesServiceResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CashMovementService
{
    use HandlesServiceResponse;

    public function __construct(
        private readonly FinancialClassificationService $classificationService = new FinancialClassificationService(),
    ) {}

    public function syncForPayablePayment(AccountPayableInstallmentPayment $payment, ?int $userId = null): ?CashMovement
    {
        return $this->syncFromPayment(
            payment: $payment->loadMissing('installment.accountPayable'),
            direction: CashMovementDirection::OUTFLOW,
            categoryScope: 'payable',
            descriptionPrefix: 'Pagamento',
            userId: $userId,
        );
    }

    public function syncForReceivablePayment(AccountReceivableInstallmentPayment $payment, ?int $userId = null): ?CashMovement
    {
        return $this->syncFromPayment(
            payment: $payment->loadMissing('installment.accountReceivable'),
            direction: CashMovementDirection::INFLOW,
            categoryScope: 'receivable',
            descriptionPrefix: 'Recebimento',
            userId: $userId,
        );
    }

    public function reverseForPayablePayment(AccountPayableInstallmentPayment $payment, ?int $userId = null): ?CashMovement
    {
        return $this->reverseForOrigin(AccountPayableInstallmentPayment::class, $payment->id, $userId);
    }

    public function reverseForReceivablePayment(AccountReceivableInstallmentPayment $payment, ?int $userId = null): ?CashMovement
    {
        return $this->reverseForOrigin(AccountReceivableInstallmentPayment::class, $payment->id, $userId);
    }

    public function createManual(array $data, ?int $userId = null): ?CashMovement
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($data, $userId) {
                $account = $this->resolveFinancialAccount((int) $data['financial_account_id'], (int) $data['company_id']);
                $category = $this->classificationService->assertCategoryIsUsable(
                    (int) $data['financial_category_id'],
                    (int) $data['company_id'],
                    'cash_movement'
                );

                $movement = CashMovement::create([
                    'company_id' => $data['company_id'],
                    'financial_account_id' => $account->id,
                    'financial_category_id' => $category->id,
                    'direction' => $data['direction'],
                    'transaction_date' => $data['transaction_date'],
                    'amount' => $data['amount'],
                    'description' => $data['description'],
                    'origin_type' => 'manual',
                    'origin_id' => null,
                    'transfer_group_id' => $data['transfer_group_id'] ?? null,
                    'notes' => $data['notes'] ?? null,
                    'created_by' => $userId,
                    'updated_by' => $userId,
                ]);

                $this->setSuccess('Movimento financeiro criado com sucesso.');

                return $movement;
            });
        } catch (ValidationException $e) {
            $this->setError('Falha de validacao do movimento financeiro.', $e->errors(), 422);
            return null;
        } catch (\Exception $e) {
            $this->setError('Erro ao criar movimento financeiro.');

            Log::error($this->getMessage(), [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'payload' => $data,
            ]);

            return null;
        }
    }

    public function updateManual(CashMovement $movement, array $data, ?int $userId = null): ?CashMovement
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($movement, $data, $userId) {
                $accountId = (int) ($data['financial_account_id'] ?? $movement->financial_account_id);
                $companyId = (int) ($data['company_id'] ?? $movement->company_id);
                $categoryId = (int) ($data['financial_category_id'] ?? $movement->financial_category_id);

                $account = $this->resolveFinancialAccount($accountId, $companyId);
                $category = $this->classificationService->assertCategoryIsUsable($categoryId, $companyId, 'cash_movement');

                $movement->update([
                    'financial_account_id' => $account->id,
                    'financial_category_id' => $category->id,
                    'direction' => $data['direction'] ?? $movement->direction?->value,
                    'transaction_date' => $data['transaction_date'] ?? $movement->transaction_date?->toDateString(),
                    'amount' => $data['amount'] ?? $movement->amount,
                    'description' => $data['description'] ?? $movement->description,
                    'transfer_group_id' => $data['transfer_group_id'] ?? $movement->transfer_group_id,
                    'notes' => $data['notes'] ?? $movement->notes,
                    'updated_by' => $userId,
                ]);

                $this->setSuccess('Movimento financeiro atualizado com sucesso.');

                return $movement->fresh();
            });
        } catch (ValidationException $e) {
            $this->setError('Falha de validacao do movimento financeiro.', $e->errors(), 422);
            return null;
        } catch (\Exception $e) {
            $this->setError('Erro ao atualizar movimento financeiro.');

            Log::error($this->getMessage(), [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'movement_id' => $movement->id,
                'payload' => $data,
            ]);

            return null;
        }
    }

    private function syncFromPayment(
        AccountPayableInstallmentPayment|AccountReceivableInstallmentPayment $payment,
        CashMovementDirection $direction,
        string $categoryScope,
        string $descriptionPrefix,
        ?int $userId = null,
    ): ?CashMovement {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($payment, $direction, $categoryScope, $descriptionPrefix, $userId) {
                $account = $this->resolveFinancialAccount((int) $payment->financial_account_id, (int) $payment->company_id);

                $installment = $payment->installment;
                $categoryId = $installment->financial_category_id
                    ? $this->classificationService->assertCategoryIsUsable(
                        (int) $installment->financial_category_id,
                        (int) $payment->company_id,
                        $categoryScope
                    )->id
                    : null;

                $movement = CashMovement::query()->firstOrNew([
                    'origin_type' => $payment::class,
                    'origin_id' => $payment->id,
                ]);

                $movement->fill([
                    'company_id' => $payment->company_id,
                    'financial_account_id' => $account->id,
                    'financial_category_id' => $categoryId,
                    'direction' => $direction->value,
                    'transaction_date' => $payment->payment_date?->toDateString(),
                    'amount' => $payment->amount,
                    'description' => $this->buildPaymentDescription($payment, $descriptionPrefix),
                    'notes' => $payment->notes,
                    'reversed_at' => null,
                    'reversal_of_id' => null,
                    'updated_by' => $userId,
                ]);

                if (! $movement->exists) {
                    $movement->created_by = $userId;
                }

                $movement->save();

                $this->setSuccess('Movimento financeiro sincronizado com sucesso.');

                return $movement->fresh();
            });
        } catch (ValidationException $e) {
            $this->setError('Falha de validacao do movimento financeiro.', $e->errors(), 422);
            return null;
        } catch (\Exception $e) {
            $this->setError('Erro ao sincronizar movimento financeiro.');

            Log::error($this->getMessage(), [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'payment_id' => $payment->id,
                'origin_type' => $payment::class,
            ]);

            return null;
        }
    }

    private function reverseForOrigin(string $originType, int $originId, ?int $userId = null): ?CashMovement
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($originType, $originId, $userId) {
                $movement = CashMovement::query()
                    ->where('origin_type', $originType)
                    ->where('origin_id', $originId)
                    ->first();

                if (! $movement) {
                    $this->setSuccess('Nenhum movimento financeiro precisava ser revertido.');
                    return null;
                }

                if ($movement->reversed_at !== null) {
                    $this->setSuccess('Movimento financeiro ja revertido anteriormente.');

                    return $movement->reversals()->latest('id')->first();
                }

                $reversal = CashMovement::create([
                    'company_id' => $movement->company_id,
                    'financial_account_id' => $movement->financial_account_id,
                    'financial_category_id' => $movement->financial_category_id,
                    'direction' => $movement->direction === CashMovementDirection::INFLOW
                        ? CashMovementDirection::OUTFLOW->value
                        : CashMovementDirection::INFLOW->value,
                    'transaction_date' => now()->toDateString(),
                    'amount' => $movement->amount,
                    'description' => 'Estorno: ' . $movement->description,
                    'notes' => $movement->notes,
                    'transfer_group_id' => $movement->transfer_group_id ?? (string) Str::uuid(),
                    'reversal_of_id' => $movement->id,
                    'created_by' => $userId,
                    'updated_by' => $userId,
                ]);

                $movement->update([
                    'reversed_at' => now(),
                    'updated_by' => $userId,
                ]);

                $this->setSuccess('Movimento financeiro revertido com sucesso.');

                return $reversal;
            });
        } catch (\Exception $e) {
            $this->setError('Erro ao reverter movimento financeiro.');

            Log::error($this->getMessage(), [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'origin_type' => $originType,
                'origin_id' => $originId,
            ]);

            return null;
        }
    }

    private function resolveFinancialAccount(int $financialAccountId, int $companyId): FinancialAccount
    {
        $account = FinancialAccount::query()
            ->where('company_id', $companyId)
            ->find($financialAccountId);

        if (! $account) {
            throw ValidationException::withMessages([
                'financial_account_id' => ['Conta financeira nao encontrada para a empresa informada.'],
            ]);
        }

        if (! $account->is_active) {
            throw ValidationException::withMessages([
                'financial_account_id' => ['A conta financeira selecionada esta inativa.'],
            ]);
        }

        return $account;
    }

    private function buildPaymentDescription(
        AccountPayableInstallmentPayment|AccountReceivableInstallmentPayment $payment,
        string $prefix,
    ): string {
        $installment = $payment->installment;
        $headerDescription = $payment instanceof AccountPayableInstallmentPayment
            ? $installment->accountPayable?->description
            : $installment->accountReceivable?->description;
        $document = $installment->notes ?: $headerDescription ?: 'lancamento financeiro';
        $sequence = $installment->sequence_number ? "parcela {$installment->sequence_number}" : 'parcela';

        return trim("{$prefix} {$sequence} - {$document}", ' -');
    }
}
