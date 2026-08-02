<?php

namespace App\Services\ProductionRequest;

use App\Enum\AccountReceivable\Status as AccountReceivableStatus;
use App\Enum\Payment\Condition as PaymentCondition;
use App\Enum\Payment\Method as PaymentMethod;
use App\Enum\ProductionRequest\Status;
use App\Models\AccountReceivableInstallment;
use App\Models\CardPaymentProfile;
use App\Models\FinancialAccount;
use App\Models\Product;
use App\Models\ProductionRequest;
use App\Services\AccountReceivable\AccountReceivableService;
use App\Traits\HandlesServiceResponse;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductionRequestService
{
    use HandlesServiceResponse;

    public function create(array $data, int $createdBy): ?ProductionRequest
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($data, $createdBy): ProductionRequest {
                $validated = $this->validateRequestData($data, false);
                $validated['company_id'] = (int) $validated['company_id'];
                $validated['created_by'] = $createdBy;
                $validated['updated_by'] = $createdBy;
                $validated['status'] = Status::OPEN->value;

                $request = ProductionRequest::query()->create($validated);

                $this->setSuccess('Pedido para produção criado com sucesso.');

                return $request;
            });
        } catch (ValidationException $e) {
            $this->setError('Falha de validacao dos dados.', $e->errors());

            return null;
        } catch (\Throwable $e) {
            $this->setError('Erro ao criar pedido para producao.', ['exception' => [$e->getMessage()]]);

            return null;
        }
    }

    public function update(ProductionRequest $request, array $data, int $updatedBy): ?ProductionRequest
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($request, $data, $updatedBy): ProductionRequest {
                if ($request->isDelivered()) {
                    throw ValidationException::withMessages([
                        'status' => ['Nao e permitido editar um pedido entregue.'],
                    ]);
                }

                if ($request->isCancelled()) {
                    throw ValidationException::withMessages([
                        'status' => ['Nao e permitido editar um pedido cancelado.'],
                    ]);
                }

                $validated = $this->validateRequestData($data, true);
                $validated['updated_by'] = $updatedBy;

                $request->update($validated);
                $request->refresh();

                $this->setSuccess('Pedido para produção atualizado com sucesso.');

                return $request;
            });
        } catch (ValidationException $e) {
            $this->setError('Falha de validacao dos dados.', $e->errors());

            return null;
        } catch (\Throwable $e) {
            $this->setError('Erro ao atualizar pedido para producao.', ['exception' => [$e->getMessage()]]);

            return null;
        }
    }

    public function deliver(ProductionRequest $request, array $data, int $userId): ?ProductionRequest
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($request, $data, $userId): ProductionRequest {
                $request->loadMissing('items.product');

                if ($request->isDelivered()) {
                    throw ValidationException::withMessages([
                        'status' => ['Este pedido ja foi entregue.'],
                    ]);
                }

                if ($request->isCancelled()) {
                    throw ValidationException::withMessages([
                        'status' => ['Nao e possivel entregar um pedido cancelado.'],
                    ]);
                }

                if ($request->account_receivable_id) {
                    throw ValidationException::withMessages([
                        'account_receivable_id' => ['Este pedido ja possui conta a receber vinculada.'],
                    ]);
                }

                if ($request->items->isEmpty()) {
                    throw ValidationException::withMessages([
                        'items' => ['Adicione pelo menos um item antes de entregar o pedido.'],
                    ]);
                }

                if (blank($request->customer_id) && blank($request->manual_counterparty_name)) {
                    throw ValidationException::withMessages([
                        'customer_id' => ['Informe um cliente ou contraparte avulsa.'],
                    ]);
                }

                if (blank($request->financial_category_id)) {
                    throw ValidationException::withMessages([
                        'financial_category_id' => ['Informe a categoria financeira do pedido.'],
                    ]);
                }

                $totalAmount = round((float) $request->total_amount, 2);

                if ($totalAmount <= 0) {
                    throw ValidationException::withMessages([
                        'items' => ['O valor total do pedido precisa ser maior que zero.'],
                    ]);
                }

                $receivableService = app(AccountReceivableService::class);
                $receivable = $receivableService->create($this->buildReceivablePayload($request), $userId);

                if ($receivableService->hasError() || $receivable === null) {
                    throw ValidationException::withMessages(
                        $receivableService->getErrors() !== []
                            ? $receivableService->getErrors()
                            : ['account_receivable' => [$receivableService->getMessage() ?: 'Nao foi possivel gerar a conta a receber.']]
                    );
                }

                if ((bool) ($data['mark_as_received'] ?? false)) {
                    $this->registerPayments($receivableService, $receivable->installments()->get(), $data, $request, $userId);
                }

                $timestamp = Carbon::parse((string) ($data['delivered_at'] ?? now()->toDateTimeString()));

                $request->update([
                    'status' => Status::DELIVERED->value,
                    'closed_at' => $timestamp,
                    'delivered_at' => $timestamp,
                    'account_receivable_id' => $receivable->id,
                    'updated_by' => $userId,
                ]);

                $request->refresh();

                $this->setSuccess('Pedido entregue com sucesso.');

                return $request;
            });
        } catch (ValidationException $e) {
            $this->setError('Falha de validacao dos dados.', $e->errors());

            return null;
        } catch (\Throwable $e) {
            $this->setError('Erro ao entregar pedido para producao.', ['exception' => [$e->getMessage()]]);

            return null;
        }
    }

    public function cancel(ProductionRequest $request, int $userId): bool
    {
        $this->resetResponse();

        try {
            if ($request->isDelivered()) {
                throw ValidationException::withMessages([
                    'status' => ['Nao e permitido cancelar um pedido entregue.'],
                ]);
            }

            $request->update([
                'status' => Status::CANCELLED->value,
                'updated_by' => $userId,
            ]);

            $this->setSuccess('Pedido cancelado com sucesso.');

            return true;
        } catch (ValidationException $e) {
            $this->setError('Falha de validacao dos dados.', $e->errors());

            return false;
        } catch (\Throwable $e) {
            $this->setError('Erro ao cancelar pedido para producao.', ['exception' => [$e->getMessage()]]);

            return false;
        }
    }

    private function validateRequestData(array $data, bool $partial): array
    {
        $rules = [
            'company_id' => [$partial ? 'sometimes' : 'required', 'integer', 'exists:companies,id'],
            'customer_id' => [$partial ? 'sometimes' : 'nullable', 'nullable', 'integer', 'exists:partners,id'],
            'manual_counterparty_name' => ['nullable', 'string', 'max:255'],
            'order_date' => [$partial ? 'sometimes' : 'required', 'date'],
            'payment_method' => ['nullable', Rule::enum(PaymentMethod::class)],
            'payment_condition' => ['nullable', Rule::enum(PaymentCondition::class)],
            'financial_category_id' => ['nullable', 'integer', 'exists:financial_categories,id'],
            'observations' => ['nullable', 'string'],
        ];

        $validated = validator($data, $rules)->validate();

        $customerId = $validated['customer_id'] ?? $data['customer_id'] ?? null;
        $manualName = trim((string) ($validated['manual_counterparty_name'] ?? $data['manual_counterparty_name'] ?? ''));

        if (blank($customerId) && $manualName === '') {
            throw ValidationException::withMessages([
                'customer_id' => ['Informe um cliente ou contraparte avulsa.'],
            ]);
        }

        if (filled($customerId) && $manualName !== '') {
            $validated['manual_counterparty_name'] = null;
        }

        return $validated;
    }

    private function buildReceivablePayload(ProductionRequest $request): array
    {
        $condition = $request->payment_condition;
        $paymentMethod = $request->payment_method;
        $dueDate = $this->resolveFirstDueDate($request)->toDateString();
        $installmentCount = max(1, $condition?->installments() ?: 1);

        return [
            'customer_id' => $request->customer_id,
            'manual_counterparty_name' => $request->manual_counterparty_name,
            'company_id' => $request->company_id,
            'status' => AccountReceivableStatus::PENDING->value,
            'due_date' => $dueDate,
            'paid_date' => null,
            'due_amount' => round((float) $request->total_amount, 2),
            'paid_amount' => 0,
            'document_number' => $request->number,
            'description' => sprintf('Referente ao pedido para producao %s', $request->number),
            'paid' => false,
            'payment_method' => $paymentMethod?->value,
            'payment_condition' => $condition?->value,
            'card_payment_profile_id' => data_get($request->additional_info, 'card_payment_profile_id'),
            'payment_date' => data_get($request->additional_info, 'payment_date'),
            'financial_category_id' => $request->financial_category_id,
            'installment_count' => $installmentCount,
            'installment_due_mode' => $installmentCount > 1
                ? ($condition?->isTerm() ? $condition->value : PaymentCondition::DAYS_30->value)
                : PaymentCondition::DAYS_30->value,
        ];
    }

    private function resolveFirstDueDate(ProductionRequest $request): Carbon
    {
        $baseDate = Carbon::parse($request->order_date ?? now()->toDateString());
        $method = $request->payment_method;
        $condition = $request->payment_condition;

        if ($method === PaymentMethod::CREDIT_CARD) {
            $profileId = (int) data_get($request->additional_info, 'card_payment_profile_id', 0);
            $paymentDate = (string) data_get($request->additional_info, 'payment_date', $baseDate->toDateString());

            if ($profileId <= 0) {
                throw ValidationException::withMessages([
                    'card_payment_profile_id' => ['Informe o perfil de recebimento em cartao.'],
                ]);
            }

            $profile = CardPaymentProfile::query()
                ->where('company_id', $request->company_id)
                ->where('active', true)
                ->find($profileId);

            if (! $profile) {
                throw ValidationException::withMessages([
                    'card_payment_profile_id' => ['Perfil de cartao invalido para a empresa informada.'],
                ]);
            }

            return Carbon::parse($paymentDate)->addDays((int) $profile->settlement_days);
        }

        if ($condition === null || $condition->isCash() || $condition === PaymentCondition::CUSTOM) {
            return $baseDate;
        }

        return $baseDate->copy()->addDays($condition->days());
    }

    /**
     * @param  Collection<int, AccountReceivableInstallment>  $installments
     */
    private function registerPayments(AccountReceivableService $receivableService, $installments, array $data, ProductionRequest $request, int $userId): void
    {
        $financialAccountId = (int) ($data['financial_account_id'] ?? 0);

        if ($financialAccountId <= 0) {
            throw ValidationException::withMessages([
                'financial_account_id' => ['Selecione a conta financeira para registrar o recebimento.'],
            ]);
        }

        $account = FinancialAccount::query()
            ->where('company_id', $request->company_id)
            ->active()
            ->find($financialAccountId);

        if (! $account) {
            throw ValidationException::withMessages([
                'financial_account_id' => ['Conta financeira invalida para a empresa informada.'],
            ]);
        }

        $paymentDate = (string) ($data['received_at'] ?? now()->toDateString());

        foreach ($installments as $installment) {
            $amount = round((float) $installment->balance_amount, 2);

            if ($amount <= 0) {
                continue;
            }

            $payment = $receivableService->registerInstallmentPayment(
                $installment,
                $amount,
                $paymentDate,
                [
                    'financial_account_id' => $account->id,
                    'description' => sprintf('Recebimento automatico do pedido para producao %s', $request->number),
                    'user_id' => $userId,
                ],
            );

            if ($receivableService->hasError() || $payment === null) {
                throw ValidationException::withMessages(
                    $receivableService->getErrors() !== []
                        ? $receivableService->getErrors()
                        : ['payment' => [$receivableService->getMessage() ?: 'Nao foi possivel registrar o recebimento.']]
                );
            }
        }
    }

    public function fillItemDefaults(Product $product): array
    {
        return [
            'description' => $product->name,
            'unit_of_measure' => $product->unit?->value ?? 'UN',
            'unit_price' => round((float) ($product->sale_price_value ?? 0), 2),
        ];
    }
}
