<?php

namespace App\Services\Financial;

use App\Enum\Financial\CashMovementDirection;
use App\Models\AccountPayableInstallmentPayment;
use App\Models\AccountReceivableInstallmentPayment;
use App\Models\CashMovement;
use App\Models\Company;
use App\Models\FinancialCategory;
use App\Models\FinancialAccount;
use App\Models\Partner;
use App\Services\Audit\AuditRecorder;
use App\Support\Financial\InstallmentDescription;
use App\Traits\HandlesServiceResponse;
use Illuminate\Database\Eloquent\Collection;
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
            payment: $payment->loadMissing('installment.accountPayable.supplier'),
            direction: CashMovementDirection::OUTFLOW,
            categoryScope: 'payable',
            descriptionPrefix: 'Pagamento',
            userId: $userId,
        );
    }

    public function syncForReceivablePayment(AccountReceivableInstallmentPayment $payment, ?int $userId = null): ?CashMovement
    {
        return $this->syncFromPayment(
            payment: $payment->loadMissing('installment.accountReceivable.customer'),
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
                $audit = app(AuditRecorder::class);
                $this->assertAmountIsPositive((float) ($data['amount'] ?? 0));

                $companyId = (int) $data['company_id'];
                $account = $this->resolveFinancialAccount((int) $data['financial_account_id'], $companyId);
                $category = $this->classificationService->assertCategoryIsUsable(
                    (int) $data['financial_category_id'],
                    $companyId,
                    'cash_movement'
                );
                $counterpartyPartner = $this->resolveCounterpartyPartner($data['counterparty_partner_id'] ?? null);
                $manualCounterpartyName = $this->normalizeCounterpartyName($data['manual_counterparty_name'] ?? null);
                $counterpartyFinancialAccount = $this->resolveCounterpartyFinancialAccount(
                    companyId: $companyId,
                    financialAccountId: $account->id,
                    counterpartyFinancialAccountId: $data['counterparty_financial_account_id'] ?? null,
                );

                $movement = CashMovement::create([
                    'company_id' => $companyId,
                    'financial_account_id' => $account->id,
                    'financial_category_id' => $category->id,
                    'direction' => $data['direction'],
                    'transaction_date' => $data['transaction_date'],
                    'amount' => $data['amount'],
                    'description' => $data['description'],
                    'origin_type' => 'manual',
                    'origin_id' => null,
                    'counterparty_partner_id' => $counterpartyPartner?->id,
                    'manual_counterparty_name' => $manualCounterpartyName,
                    'counterparty_financial_account_id' => $counterpartyFinancialAccount?->id,
                    'transfer_group_id' => $data['transfer_group_id'] ?? null,
                    'notes' => $data['notes'] ?? null,
                    'participants_snapshot' => $this->buildParticipantsSnapshot(
                        companyId: $companyId,
                        financialAccount: $account,
                        counterpartyPartner: $counterpartyPartner,
                        manualCounterpartyName: $manualCounterpartyName,
                        counterpartyFinancialAccount: $counterpartyFinancialAccount,
                    ),
                    'created_by' => $userId,
                    'updated_by' => $userId,
                ]);

                $audit->recordModelEvent(
                    $movement,
                    'cash_movement.created',
                    'Movimento financeiro criado',
                    null,
                    $audit->snapshot($movement),
                    $userId,
                );

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
                $audit = app(AuditRecorder::class);
                $before = $audit->snapshot($movement);

                if ($this->isTransferMovement($movement)) {
                    throw ValidationException::withMessages([
                        'transfer_group_id' => ['Transferencias devem ser editadas pelo fluxo especifico de transferencia.'],
                    ]);
                }

                $accountId = (int) ($data['financial_account_id'] ?? $movement->financial_account_id);
                $companyId = (int) ($data['company_id'] ?? $movement->company_id);
                $categoryId = (int) ($data['financial_category_id'] ?? $movement->financial_category_id);
                $this->assertAmountIsPositive((float) ($data['amount'] ?? $movement->amount));

                $account = $this->resolveFinancialAccount($accountId, $companyId);
                $category = $this->classificationService->assertCategoryIsUsable($categoryId, $companyId, 'cash_movement');
                $counterpartyPartner = $this->resolveCounterpartyPartner(
                    array_key_exists('counterparty_partner_id', $data)
                        ? $data['counterparty_partner_id']
                        : $movement->counterparty_partner_id
                );
                $manualCounterpartyName = $this->normalizeCounterpartyName(
                    array_key_exists('manual_counterparty_name', $data)
                        ? $data['manual_counterparty_name']
                        : $movement->manual_counterparty_name
                );
                $counterpartyFinancialAccount = $this->resolveCounterpartyFinancialAccount(
                    companyId: $companyId,
                    financialAccountId: $account->id,
                    counterpartyFinancialAccountId: array_key_exists('counterparty_financial_account_id', $data)
                        ? $data['counterparty_financial_account_id']
                        : $movement->counterparty_financial_account_id,
                );

                $movement->update([
                    'financial_account_id' => $account->id,
                    'financial_category_id' => $category->id,
                    'direction' => $data['direction'] ?? $movement->direction?->value,
                    'transaction_date' => $data['transaction_date'] ?? $movement->transaction_date?->toDateString(),
                    'amount' => $data['amount'] ?? $movement->amount,
                    'description' => $data['description'] ?? $movement->description,
                    'counterparty_partner_id' => $counterpartyPartner?->id,
                    'manual_counterparty_name' => $manualCounterpartyName,
                    'counterparty_financial_account_id' => $counterpartyFinancialAccount?->id,
                    'transfer_group_id' => $data['transfer_group_id'] ?? $movement->transfer_group_id,
                    'notes' => $data['notes'] ?? $movement->notes,
                    'participants_snapshot' => $this->buildParticipantsSnapshot(
                        companyId: $companyId,
                        financialAccount: $account,
                        counterpartyPartner: $counterpartyPartner,
                        manualCounterpartyName: $manualCounterpartyName,
                        counterpartyFinancialAccount: $counterpartyFinancialAccount,
                    ),
                    'updated_by' => $userId,
                ]);

                $movement->refresh();
                $audit->recordModelEvent(
                    $movement,
                    'cash_movement.updated',
                    'Movimento financeiro atualizado',
                    $before,
                    $audit->snapshot($movement),
                    $userId,
                );

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

    public function createTransfer(array $data, ?int $userId = null): ?CashMovement
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($data, $userId) {
                $audit = app(AuditRecorder::class);
                $companyId = (int) $data['company_id'];
                $amount = (float) ($data['amount'] ?? 0);
                $this->assertAmountIsPositive($amount);

                $sourceAccount = $this->resolveFinancialAccount((int) $data['source_financial_account_id'], $companyId);
                $destinationAccount = $this->resolveCounterpartyFinancialAccount(
                    companyId: $companyId,
                    financialAccountId: $sourceAccount->id,
                    counterpartyFinancialAccountId: $data['destination_financial_account_id'] ?? null,
                );
                $category = $this->classificationService->assertCategoryIsUsable(
                    (int) $data['financial_category_id'],
                    $companyId,
                    'cash_movement'
                );
                $transferGroupId = (string) Str::uuid();

                $outflow = $this->createTransferMovement(
                    companyId: $companyId,
                    account: $sourceAccount,
                    counterpartyAccount: $destinationAccount,
                    category: $category,
                    direction: CashMovementDirection::OUTFLOW,
                    transactionDate: (string) $data['transaction_date'],
                    amount: $amount,
                    description: (string) $data['description'],
                    notes: $data['notes'] ?? null,
                    transferGroupId: $transferGroupId,
                    userId: $userId,
                );

                $this->createTransferMovement(
                    companyId: $companyId,
                    account: $destinationAccount,
                    counterpartyAccount: $sourceAccount,
                    category: $category,
                    direction: CashMovementDirection::INFLOW,
                    transactionDate: (string) $data['transaction_date'],
                    amount: $amount,
                    description: (string) $data['description'],
                    notes: $data['notes'] ?? null,
                    transferGroupId: $transferGroupId,
                    userId: $userId,
                );

                $outflow->refresh();
                $audit->recordModelEvent(
                    $outflow,
                    'cash_movement.transfer_created',
                    'Transferência entre contas criada',
                    null,
                    $audit->snapshot($outflow),
                    $userId,
                    null,
                    [
                        'transfer_group_id' => $transferGroupId,
                        'destination_financial_account_id' => $destinationAccount->id,
                    ],
                );

                $this->setSuccess('Transferencia entre contas criada com sucesso.', [
                    'transfer_group_id' => $transferGroupId,
                ]);

                return $outflow->fresh();
            });
        } catch (ValidationException $e) {
            $this->setError('Falha de validacao da transferencia.', $e->errors(), 422);

            return null;
        } catch (\Exception $e) {
            $this->setError('Erro ao criar transferencia entre contas.');

            Log::error($this->getMessage(), [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'payload' => $data,
            ]);

            return null;
        }
    }

    public function updateTransfer(CashMovement $movement, array $data, ?int $userId = null): ?CashMovement
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($movement, $data, $userId) {
                $audit = app(AuditRecorder::class);
                [$outflow, $inflow] = $this->resolveTransferPair($movement);
                $before = $audit->snapshot($movement);

                if ($outflow->reversed_at !== null || $inflow->reversed_at !== null) {
                    throw ValidationException::withMessages([
                        'transfer_group_id' => ['Transferencias estornadas nao podem ser editadas.'],
                    ]);
                }

                $companyId = (int) ($data['company_id'] ?? $movement->company_id);
                $amount = (float) ($data['amount'] ?? $movement->amount);
                $this->assertAmountIsPositive($amount);

                $sourceAccount = $this->resolveFinancialAccount((int) $data['source_financial_account_id'], $companyId);
                $destinationAccount = $this->resolveCounterpartyFinancialAccount(
                    companyId: $companyId,
                    financialAccountId: $sourceAccount->id,
                    counterpartyFinancialAccountId: $data['destination_financial_account_id'] ?? null,
                );
                $category = $this->classificationService->assertCategoryIsUsable(
                    (int) $data['financial_category_id'],
                    $companyId,
                    'cash_movement'
                );

                $commonData = [
                    'company_id' => $companyId,
                    'financial_category_id' => $category->id,
                    'transaction_date' => $data['transaction_date'],
                    'amount' => $amount,
                    'description' => $data['description'],
                    'notes' => $data['notes'] ?? null,
                    'updated_by' => $userId,
                ];

                $outflow->update([
                    ...$commonData,
                    'financial_account_id' => $sourceAccount->id,
                    'direction' => CashMovementDirection::OUTFLOW->value,
                    'counterparty_financial_account_id' => $destinationAccount->id,
                    'participants_snapshot' => $this->buildParticipantsSnapshot(
                        companyId: $companyId,
                        financialAccount: $sourceAccount,
                        counterpartyPartner: null,
                        manualCounterpartyName: null,
                        counterpartyFinancialAccount: $destinationAccount,
                    ),
                ]);

                $inflow->update([
                    ...$commonData,
                    'financial_account_id' => $destinationAccount->id,
                    'direction' => CashMovementDirection::INFLOW->value,
                    'counterparty_financial_account_id' => $sourceAccount->id,
                    'participants_snapshot' => $this->buildParticipantsSnapshot(
                        companyId: $companyId,
                        financialAccount: $destinationAccount,
                        counterpartyPartner: null,
                        manualCounterpartyName: null,
                        counterpartyFinancialAccount: $sourceAccount,
                    ),
                ]);

                $targetMovement = $movement->direction === CashMovementDirection::OUTFLOW
                    ? $outflow->fresh()
                    : $inflow->fresh();

                $audit->recordModelEvent(
                    $targetMovement,
                    'cash_movement.updated',
                    'Transferência entre contas atualizada',
                    $before,
                    $audit->snapshot($targetMovement),
                    $userId,
                    null,
                    [
                        'transfer_group_id' => $movement->transfer_group_id,
                        'source_financial_account_id' => $sourceAccount->id,
                        'destination_financial_account_id' => $destinationAccount->id,
                    ],
                );

                $this->setSuccess('Transferencia entre contas atualizada com sucesso.');

                return $targetMovement;
            });
        } catch (ValidationException $e) {
            $this->setError('Falha de validacao da transferencia.', $e->errors(), 422);

            return null;
        } catch (\Exception $e) {
            $this->setError('Erro ao atualizar transferencia entre contas.');

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

    public function reverseTransfer(CashMovement $movement, ?int $userId = null): ?CashMovement
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($movement, $userId) {
                $audit = app(AuditRecorder::class);
                [$outflow, $inflow] = $this->resolveTransferPair($movement);
                $before = $audit->snapshot($movement);

                if (
                    $outflow->reversed_at !== null
                    || $inflow->reversed_at !== null
                    || $outflow->reversals()->exists()
                    || $inflow->reversals()->exists()
                ) {
                    throw ValidationException::withMessages([
                        'transfer_group_id' => ['Esta transferencia ja foi estornada anteriormente.'],
                    ]);
                }

                $outflowReversal = CashMovement::create([
                    'company_id' => $outflow->company_id,
                    'financial_account_id' => $outflow->financial_account_id,
                    'financial_category_id' => $outflow->financial_category_id,
                    'direction' => CashMovementDirection::INFLOW->value,
                    'transaction_date' => now()->toDateString(),
                    'amount' => $outflow->amount,
                    'description' => 'Estorno: ' . $outflow->description,
                    'origin_type' => 'manual',
                    'origin_id' => null,
                    'counterparty_partner_id' => null,
                    'counterparty_financial_account_id' => $outflow->counterparty_financial_account_id,
                    'transfer_group_id' => $outflow->transfer_group_id,
                    'notes' => $outflow->notes,
                    'participants_snapshot' => $outflow->participants_snapshot,
                    'reversal_of_id' => $outflow->id,
                    'created_by' => $userId,
                    'updated_by' => $userId,
                ]);

                CashMovement::create([
                    'company_id' => $inflow->company_id,
                    'financial_account_id' => $inflow->financial_account_id,
                    'financial_category_id' => $inflow->financial_category_id,
                    'direction' => CashMovementDirection::OUTFLOW->value,
                    'transaction_date' => now()->toDateString(),
                    'amount' => $inflow->amount,
                    'description' => 'Estorno: ' . $inflow->description,
                    'origin_type' => 'manual',
                    'origin_id' => null,
                    'counterparty_partner_id' => null,
                    'counterparty_financial_account_id' => $inflow->counterparty_financial_account_id,
                    'transfer_group_id' => $inflow->transfer_group_id,
                    'notes' => $inflow->notes,
                    'participants_snapshot' => $inflow->participants_snapshot,
                    'reversal_of_id' => $inflow->id,
                    'created_by' => $userId,
                    'updated_by' => $userId,
                ]);

                $timestamp = now();

                $outflow->update([
                    'reversed_at' => $timestamp,
                    'updated_by' => $userId,
                ]);

                $inflow->update([
                    'reversed_at' => $timestamp,
                    'updated_by' => $userId,
                ]);

                $this->setSuccess('Transferencia entre contas estornada com sucesso.');

                $targetMovement = $movement->id === $outflow->id
                    ? $outflowReversal->fresh()
                    : $movement->fresh()?->reversals()->latest('id')->first();

                if ($targetMovement) {
                    $audit->recordModelEvent(
                        $targetMovement,
                        'cash_movement.transfer_reversed',
                        'Transferência entre contas estornada',
                        $before,
                        $audit->snapshot($targetMovement),
                        $userId,
                        null,
                        [
                            'transfer_group_id' => $movement->transfer_group_id,
                        ],
                    );
                }

                return $targetMovement;
            });
        } catch (ValidationException $e) {
            $this->setError('Falha de validacao do estorno da transferencia.', $e->errors(), 422);

            return null;
        } catch (\Exception $e) {
            $this->setError('Erro ao estornar transferencia entre contas.');

            Log::error($this->getMessage(), [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'movement_id' => $movement->id,
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
                $audit = app(AuditRecorder::class);
                $account = $this->resolveFinancialAccount((int) $payment->financial_account_id, (int) $payment->company_id);
                $counterpartyPartner = $this->resolvePaymentCounterpartyPartner($payment);
                $manualCounterpartyName = $this->resolvePaymentManualCounterpartyName($payment);

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
                $before = $movement->exists ? $audit->snapshot($movement) : null;
                $isNewMovement = ! $movement->exists;

                $movement->fill([
                    'company_id' => $payment->company_id,
                    'financial_account_id' => $account->id,
                    'financial_category_id' => $categoryId,
                    'direction' => $direction->value,
                    'transaction_date' => $payment->payment_date?->toDateString(),
                    'amount' => $payment->amount,
                    'description' => $this->buildPaymentDescription($payment, $descriptionPrefix),
                    'notes' => $payment->notes,
                    'counterparty_partner_id' => $counterpartyPartner?->id,
                    'manual_counterparty_name' => $manualCounterpartyName,
                    'counterparty_financial_account_id' => null,
                    'participants_snapshot' => $this->buildParticipantsSnapshot(
                        companyId: (int) $payment->company_id,
                        financialAccount: $account,
                        counterpartyPartner: $counterpartyPartner,
                        manualCounterpartyName: $manualCounterpartyName,
                        counterpartyFinancialAccount: null,
                    ),
                    'reversed_at' => null,
                    'reversal_of_id' => null,
                    'updated_by' => $userId,
                ]);

                if (! $movement->exists) {
                    $movement->created_by = $userId;
                }

                $movement->save();
                $movement = $movement->fresh();

                $audit->recordModelEvent(
                    $movement,
                    $isNewMovement ? 'cash_movement.created' : 'cash_movement.updated',
                    $isNewMovement ? 'Movimento financeiro criado a partir de baixa' : 'Movimento financeiro atualizado a partir de baixa',
                    $before,
                    $audit->snapshot($movement),
                    $userId,
                    null,
                    [
                        'origin_type' => $payment::class,
                        'origin_id' => $payment->id,
                    ],
                );

                $this->setSuccess('Movimento financeiro sincronizado com sucesso.');

                return $movement;
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
                    'counterparty_partner_id' => $movement->counterparty_partner_id,
                    'counterparty_financial_account_id' => $movement->counterparty_financial_account_id,
                    'notes' => $movement->notes,
                    'participants_snapshot' => $movement->participants_snapshot,
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

    private function assertAmountIsPositive(float $amount): void
    {
        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'amount' => ['O valor deve ser maior que zero.'],
            ]);
        }
    }

    private function resolveCounterpartyPartner(int|string|null $counterpartyPartnerId): ?Partner
    {
        if ($counterpartyPartnerId === null || $counterpartyPartnerId === '') {
            return null;
        }

        $partner = Partner::query()->find((int) $counterpartyPartnerId);

        if (! $partner) {
            throw ValidationException::withMessages([
                'counterparty_partner_id' => ['Parceiro contraparte nao encontrado.'],
            ]);
        }

        return $partner;
    }

    private function resolveCounterpartyFinancialAccount(
        int $companyId,
        int $financialAccountId,
        int|string|null $counterpartyFinancialAccountId,
    ): ?FinancialAccount {
        if ($counterpartyFinancialAccountId === null || $counterpartyFinancialAccountId === '') {
            return null;
        }

        $counterpartyFinancialAccountId = (int) $counterpartyFinancialAccountId;

        if ($counterpartyFinancialAccountId === $financialAccountId) {
            throw ValidationException::withMessages([
                'counterparty_financial_account_id' => ['A conta contraparte deve ser diferente da conta principal.'],
            ]);
        }

        return $this->resolveFinancialAccount($counterpartyFinancialAccountId, $companyId);
    }

    /**
     * @return array{0: CashMovement, 1: CashMovement}
     */
    private function resolveTransferPair(CashMovement $movement): array
    {
        if ($movement->reversal_of_id !== null) {
            throw ValidationException::withMessages([
                'transfer_group_id' => ['Lancamentos de estorno nao podem ser editados como transferencia.'],
            ]);
        }

        if (! $this->isTransferMovement($movement)) {
            throw ValidationException::withMessages([
                'transfer_group_id' => ['O movimento informado nao pertence a uma transferencia valida.'],
            ]);
        }

        /** @var Collection<int, CashMovement> $group */
        $group = CashMovement::query()
            ->where('transfer_group_id', $movement->transfer_group_id)
            ->where('origin_type', 'manual')
            ->whereNull('reversal_of_id')
            ->orderBy('id')
            ->get();

        $outflow = $group->first(fn (CashMovement $item) => $item->direction === CashMovementDirection::OUTFLOW);
        $inflow = $group->first(fn (CashMovement $item) => $item->direction === CashMovementDirection::INFLOW);

        if (! $outflow || ! $inflow) {
            throw ValidationException::withMessages([
                'transfer_group_id' => ['Nao foi possivel localizar os dois lados da transferencia.'],
            ]);
        }

        return [$outflow, $inflow];
    }

    private function isTransferMovement(CashMovement $movement): bool
    {
        return $movement->isTransfer();
    }

    private function createTransferMovement(
        int $companyId,
        FinancialAccount $account,
        FinancialAccount $counterpartyAccount,
        FinancialCategory $category,
        CashMovementDirection $direction,
        string $transactionDate,
        float $amount,
        string $description,
        ?string $notes,
        string $transferGroupId,
        ?int $userId,
    ): CashMovement {
        return CashMovement::create([
            'company_id' => $companyId,
            'financial_account_id' => $account->id,
            'financial_category_id' => $category->id,
            'direction' => $direction->value,
            'transaction_date' => $transactionDate,
            'amount' => $amount,
            'description' => $description,
            'origin_type' => 'manual',
            'origin_id' => null,
            'counterparty_partner_id' => null,
            'counterparty_financial_account_id' => $counterpartyAccount->id,
            'transfer_group_id' => $transferGroupId,
            'notes' => $notes,
            'participants_snapshot' => $this->buildParticipantsSnapshot(
                companyId: $companyId,
                financialAccount: $account,
                counterpartyPartner: null,
                manualCounterpartyName: null,
                counterpartyFinancialAccount: $counterpartyAccount,
            ),
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);
    }

    private function buildParticipantsSnapshot(
        int $companyId,
        FinancialAccount $financialAccount,
        ?Partner $counterpartyPartner,
        ?string $manualCounterpartyName,
        ?FinancialAccount $counterpartyFinancialAccount,
    ): array {
        $company = Company::query()->find($companyId);

        return array_filter([
            'company_name' => $company?->name,
            'financial_account_name' => $financialAccount->display_name,
            'counterparty_partner_name' => $counterpartyPartner?->name ?? $manualCounterpartyName,
            'counterparty_financial_account_name' => $counterpartyFinancialAccount?->display_name,
        ], static fn ($value) => $value !== null && $value !== '');
    }

    private function normalizeCounterpartyName(?string $counterpartyName): ?string
    {
        $counterpartyName = trim((string) $counterpartyName);

        return $counterpartyName === '' ? null : $counterpartyName;
    }

    private function resolvePaymentCounterpartyPartner(
        AccountPayableInstallmentPayment|AccountReceivableInstallmentPayment $payment,
    ): ?Partner {
        $installment = $payment->installment;

        return $payment instanceof AccountPayableInstallmentPayment
            ? $installment->accountPayable?->supplier
            : $installment->accountReceivable?->customer;
    }

    private function resolvePaymentManualCounterpartyName(
        AccountPayableInstallmentPayment|AccountReceivableInstallmentPayment $payment,
    ): ?string {
        $installment = $payment->installment;

        if ($payment instanceof AccountPayableInstallmentPayment) {
            return $installment->accountPayable?->supplier_id === null
                ? $this->normalizeCounterpartyName($installment->accountPayable?->manual_counterparty_name)
                : null;
        }

        return $installment->accountReceivable?->customer_id === null
            ? $this->normalizeCounterpartyName($installment->accountReceivable?->manual_counterparty_name)
            : null;
    }

    private function buildPaymentDescription(
        AccountPayableInstallmentPayment|AccountReceivableInstallmentPayment $payment,
        string $prefix,
    ): string {
        $description = $payment instanceof AccountPayableInstallmentPayment
            ? InstallmentDescription::forPayablePayment($payment)
            : InstallmentDescription::forReceivablePayment($payment);
        $sequence = $payment->installment?->sequence_number ? "parcela {$payment->installment->sequence_number}" : 'parcela';

        return trim("{$prefix} {$sequence} - {$description}", ' -');
    }
}
