<?php

namespace App\Services\Financial\Pix;

use App\Enum\Payment\Method as PaymentMethod;
use App\Models\AccountReceivableInstallment;
use App\Models\BankAccountConnection;
use App\Models\CompanyEntitlement;
use App\Models\FinancialAccount;
use Illuminate\Validation\ValidationException;

final class PixEligibilityService
{
    /**
     * @return array<int, string>
     */
    public function financialAccountOptions(int $companyId): array
    {
        return FinancialAccount::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('name')
            ->with(['bankAccountConnections.billingProvider', 'bankAccountConnections.bank'])
            ->get()
            ->filter(fn (FinancialAccount $account): bool => $account->bankAccountConnections->contains(
                fn (BankAccountConnection $connection): bool => $connection->status === 'active'
                    && $connection->bank?->is_active === true
                    && $connection->billingProvider?->is_active === true
                    && (bool) data_get($connection->billingProvider?->capabilities, 'pix', false),
            ))
            ->mapWithKeys(fn (FinancialAccount $account): array => [$account->id => $account->name])
            ->all();
    }

    public function resolveConnection(AccountReceivableInstallment $installment): BankAccountConnection
    {
        $installment->loadMissing('accountReceivable.customer', 'financialAccount');
        $receivable = $installment->accountReceivable;

        if (! $receivable) {
            throw ValidationException::withMessages([
                'account_receivable_installment_id' => ['A parcela não possui conta a receber.'],
            ]);
        }

        if ($receivable->payment_method !== PaymentMethod::PIX) {
            throw ValidationException::withMessages([
                'payment_method' => ['A parcela não utiliza PIX.'],
            ]);
        }

        if ((float) $installment->balance_amount <= 0) {
            throw ValidationException::withMessages([
                'installment_id' => ['A parcela já foi quitada e não pode receber cobrança PIX.'],
            ]);
        }

        if (! CompanyEntitlement::enabledFor((int) $installment->company_id, (string) config('pix.entitlement'))) {
            throw ValidationException::withMessages([
                'pix_issuance' => ['A empresa não possui direito de emissão de cobranças PIX.'],
            ]);
        }

        if (! $installment->financial_account_id || ! $installment->financialAccount) {
            throw ValidationException::withMessages([
                'financial_account_id' => ['A parcela não possui uma conta financeira válida para emissão.'],
            ]);
        }

        if (
            (int) $installment->financialAccount->company_id !== (int) $installment->company_id
            || ! $installment->financialAccount->is_active
        ) {
            throw ValidationException::withMessages([
                'financial_account_id' => ['A conta financeira não pertence à empresa da parcela ou está inativa.'],
            ]);
        }

        if (! $receivable->customer?->name || ! $receivable->customer?->document_number) {
            throw ValidationException::withMessages([
                'customer' => ['O pagador precisa possuir nome e documento para emissão da cobrança PIX.'],
            ]);
        }

        $connection = BankAccountConnection::query()
            ->with(['bank', 'billingProvider'])
            ->where('company_id', $installment->company_id)
            ->where('financial_account_id', $installment->financial_account_id)
            ->active()
            ->whereHas('bank', fn ($query) => $query->where('is_active', true))
            ->whereHas('billingProvider', fn ($query) => $query->where('is_active', true))
            ->orderBy('id')
            ->get()
            ->first(fn (BankAccountConnection $candidate): bool => (bool) data_get(
                $candidate->billingProvider?->capabilities,
                'pix',
                false,
            ));

        if (! $connection) {
            throw ValidationException::withMessages([
                'bank_account_connection' => ['Não existe conexão bancária ativa com capacidade PIX para a conta financeira da parcela.'],
            ]);
        }

        return $connection;
    }
}
