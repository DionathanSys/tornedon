<?php

namespace App\Services\AccountReceivable;

use App\Models\AccountReceivableInstallment;
use App\Models\Company;
use App\Models\User;
use App\Services\Access\CompanyAuthorization;
use App\Services\Access\UserAccessService;
use App\Support\Financial\InstallmentPaymentGuard;
use App\Support\Financial\Money;
use BackedEnum;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class BulkRegisterReceivablePaymentsService
{
    public function __construct(
        private readonly AccountReceivableService $receivableService = new AccountReceivableService,
        private readonly CompanyAuthorization $authorization = new CompanyAuthorization,
        private readonly UserAccessService $userAccess = new UserAccessService,
    ) {}

    public function register(Collection $installments, Company $company, User $user, array $data, ?string $panel = null): array
    {
        $useDueDate = filter_var($data['use_due_date'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $validated = Validator::make($data, [
            'use_due_date' => ['nullable', 'boolean'],
            'payment_date' => [
                'nullable',
                'date',
                function (string $attribute, mixed $value, \Closure $fail) use ($useDueDate): void {
                    if (! $useDueDate && blank($value)) {
                        $fail('Informe a data de recebimento.');
                    }
                },
            ],
            'financial_account_id' => ['required', 'integer', Rule::exists('financial_accounts', 'id')->where('company_id', $company->id)],
            'financial_category_id' => ['nullable', 'integer', Rule::exists('financial_categories', 'id')->where('company_id', $company->id)],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [
            'payment_date.date' => 'A data de recebimento deve ser válida.',
            'financial_account_id.required' => 'Selecione a conta financeira para registrar os recebimentos.',
            'financial_account_id.exists' => 'Conta financeira não encontrada para a empresa informada.',
            'financial_category_id.exists' => 'Categoria financeira não encontrada para a empresa informada.',
        ])->validate();

        abort_unless($installments->every(fn ($installment): bool => $installment instanceof AccountReceivableInstallment
            && (int) $installment->company_id === (int) $company->id), 403);
        abort_unless($this->userAccess->allows($user, $company, 'account_receivables.access', $panel), 403);

        return DB::transaction(function () use ($installments, $company, $user, $panel, $validated, $useDueDate): array {
            $processed = 0;
            $skipped = 0;
            $amountCents = 0;
            $requiresRoleAuthorization = $this->authorization->requiresRoleAuthorization($user, $company, $panel);

            $installments = $installments->sortBy(fn (AccountReceivableInstallment $installment): string => sprintf(
                '%020d:%020d',
                $installment->account_receivable_id,
                $installment->id,
            ));

            foreach ($installments as $installment) {
                $locked = InstallmentPaymentGuard::lock($installment);
                $parent = $locked->accountReceivable;
                abort_unless($parent && (int) $parent->company_id === (int) $company->id, 403);

                if ($requiresRoleAuthorization) {
                    abort_unless($this->authorization->allowsRecord($user, $company, 'account_receivables.settle', $parent), 403);
                }

                if ($locked->ignored_at
                    || self::statusValue($locked->status) === 'cancelled'
                    || self::statusValue($parent->status) === 'cancelled'
                    || Money::stored($locked, 'balance_amount') <= 0) {
                    $skipped++;

                    continue;
                }

                $paymentDate = $useDueDate
                    ? $locked->due_date->toDateString()
                    : (string) $validated['payment_date'];
                $payment = $this->receivableService->registerInstallmentPayment(
                    $locked,
                    Money::decimal(Money::stored($locked, 'balance_amount')),
                    $paymentDate,
                    [
                        'financial_account_id' => (int) $validated['financial_account_id'],
                        'financial_category_id' => $validated['financial_category_id'] ?? null,
                        'competence_date' => $paymentDate,
                        'origin' => $useDueDate ? 'due_date' : 'manual',
                        'notes' => $validated['notes'] ?? null,
                    ],
                );

                if ($payment === null || $this->receivableService->hasError()) {
                    throw ValidationException::withMessages([
                        'payment' => $this->receivableService->getMessageUser() ?: "Não foi possível quitar a parcela #{$locked->id}.",
                    ]);
                }

                $processed++;
                $amountCents += Money::stored($payment);
            }

            return [
                'processed' => $processed,
                'skipped' => $skipped,
                'amount_cents' => $amountCents,
            ];
        });
    }

    private static function statusValue(mixed $status): ?string
    {
        return $status instanceof BackedEnum ? $status->value : $status;
    }
}
