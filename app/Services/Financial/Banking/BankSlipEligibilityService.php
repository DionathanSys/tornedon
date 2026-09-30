<?php

namespace App\Services\Financial\Banking;

use App\Enum\Payment\Method as PaymentMethod;
use App\Models\AccountReceivableInstallment;
use App\Models\BankAccountConnection;
use App\Models\CompanyEntitlement;
use App\Models\FinancialAccount;
use Illuminate\Validation\ValidationException;

final class BankSlipEligibilityService
{
    /**
     * @return array<int, string>
     */
    public function financialAccountOptions(int $companyId): array
    {
        return FinancialAccount::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->whereHas('bankAccountConnections', function ($query): void {
                $query
                    ->where('status', 'active')
                    ->whereHas('bank', fn ($bankQuery) => $bankQuery->where('is_active', true))
                    ->whereHas('billingProvider', fn ($providerQuery) => $providerQuery->where('is_active', true));
            })
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    public function resolveConnection(AccountReceivableInstallment $installment): BankAccountConnection
    {
        $installment->loadMissing('accountReceivable.customer', 'financialAccount');
        $receivable = $installment->accountReceivable;

        if (! $receivable) {
            throw ValidationException::withMessages([
                'account_receivable_installment_id' => ['A parcela nao possui conta a receber.'],
            ]);
        }

        if ($receivable->payment_method !== PaymentMethod::BANK_SLIP) {
            throw ValidationException::withMessages([
                'payment_method' => ['A parcela nao utiliza boleto bancario.'],
            ]);
        }

        if ((float) $installment->balance_amount <= 0) {
            throw ValidationException::withMessages([
                'installment_id' => ['A parcela ja foi quitada e nao pode receber boleto.'],
            ]);
        }

        if (! CompanyEntitlement::enabledFor((int) $installment->company_id, (string) config('banking.bank_slip_entitlement'))) {
            throw ValidationException::withMessages([
                'bank_slip_issuance' => ['A empresa nao possui direito de emissao de boletos.'],
            ]);
        }

        if (! $installment->financial_account_id || ! $installment->financialAccount) {
            throw ValidationException::withMessages([
                'financial_account_id' => ['A parcela nao possui uma conta financeira valida para emissao.'],
            ]);
        }

        if (
            (int) $installment->financialAccount->company_id !== (int) $installment->company_id
            || ! $installment->financialAccount->is_active
        ) {
            throw ValidationException::withMessages([
                'financial_account_id' => ['A conta financeira nao pertence a empresa da parcela ou esta inativa.'],
            ]);
        }

        if (! $receivable->customer?->name || ! $receivable->customer?->document_number) {
            throw ValidationException::withMessages([
                'customer' => ['O pagador precisa possuir nome e documento para emissao do boleto.'],
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
            ->first();

        if (! $connection) {
            throw ValidationException::withMessages([
                'bank_account_connection' => ['Nao existe conexao bancaria ativa para a conta financeira da parcela.'],
            ]);
        }

        return $connection;
    }
}
