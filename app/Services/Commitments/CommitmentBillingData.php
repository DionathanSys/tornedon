<?php

namespace App\Services\Commitments;

use App\Enum\Payment\Condition;
use App\Enum\Payment\Method;
use App\Models\CommitmentBilling;
use App\Models\CommitmentSetting;
use App\Models\CompanyPreference;
use App\Models\FinancialAccount;
use App\Models\FinancialCategory;
use App\Models\Service;
use Illuminate\Validation\ValidationException;

class CommitmentBillingData
{
    public function build(CommitmentBilling $billing, ?int $serviceId = null): array
    {
        $settings = CommitmentSetting::forCustomer($billing->company_id, $billing->customer_id)->where('is_enabled', true)->where('parser', 'chapeco')->first();
        if (! $settings) {
            $this->invalid('Habilite o fluxo de empenhos nas configurações do cliente.');
        }
        $order = $billing->serviceOrder()->with('items.service')->firstOrFail();
        if ($order->requisition()->exists()) {
            $this->invalid('Esta OS possui requisição. Use o faturamento normal; este fluxo atende somente serviços/NFS-e.');
        }
        // Confirmed fiscal texts are immutable; a retry only submits the existing document.
        if ($billing->invoice?->confirmed) {
            if (! $billing->settings_snapshot) {
                $this->invalid('Fatura confirmada sem snapshot deste fluxo. Revise pela tela de faturas.');
            }

            return $billing->settings_snapshot;
        }
        $options = $this->serviceOptions($billing);
        $serviceId ??= $settings->nfse_service_id ?: (count($options) === 1 ? (int) array_key_first($options) : null);
        if (! $serviceId || ! isset($options[$serviceId])) {
            $this->invalid('Selecione um serviço ativo presente na OS ou ajuste o serviço padrão nas configurações de empenhos.');
        }
        $footer = trim(implode(' | ', array_filter([
            preg_replace('/\s*\R\s*/u', ' | ', trim((string) $settings->nfse_footer_message)),
            'Patrimônio: '.$billing->commitment->patrimony,
            'Empenho: '.$billing->commitment->number.'/'.$billing->commitment->fiscal_year,
        ])));
        $data = [
            'payment_method' => $settings->payment_method?->value,
            'payment_condition' => $settings->payment_condition?->value,
            'payment_condition_details' => $settings->payment_condition_details,
            'financial_category_id' => $settings->financial_category_id ?? CompanyPreference::getDefaultReceivableFinancialCategoryId($billing->company_id),
            'financial_account_id' => $settings->financial_account_id,
            'nfse_service_id' => $serviceId,
            'nfse_item_description' => $options[$serviceId],
            'nfse_additional_information' => $footer,
            'mark_as_received' => false,
            'auto_bank_slip_issuance' => false,
            'auto_pix_charge_issuance' => false,
        ];
        $this->validate($billing, $data);

        return $data;
    }

    public function serviceOptions(CommitmentBilling $billing): array
    {
        return Service::query()->where('company_id', $billing->company_id)->where('is_active', true)
            ->whereIn('id', $billing->serviceOrder->items()->pluck('service_id'))->orderBy('name')->pluck('name', 'id')->all();
    }

    public function validate(CommitmentBilling $billing, array $data): void
    {
        $method = Method::tryFrom((string) ($data['payment_method'] ?? ''));
        if (! $method || ! Condition::tryFrom((string) ($data['payment_condition'] ?? ''))) {
            $this->invalid('Configure a forma e a condição de pagamento antes de faturar.');
        }
        if ($method === Method::CREDIT_CARD) {
            $this->invalid('Cartão exige instituição e data de pagamento. Utilize a confirmação normal da fatura.');
        }
        if (! isset($this->serviceOptions($billing)[$data['nfse_service_id'] ?? 0])) {
            $this->invalid('O serviço fiscal deve estar ativo e pertencer à OS e à empresa.');
        }
        if (blank($data['nfse_item_description'] ?? null) || mb_strlen($data['nfse_item_description']) > 2000
            || mb_strlen($data['nfse_additional_information'] ?? '') > 2000
            || strlen($data['nfse_item_description'] ?? '') > 2000 || strlen($data['nfse_additional_information'] ?? '') > 2000) {
            // The existing national emitter uses a 2000-byte substring. Never let it cut the mandatory references.
            $this->invalid('Corpo e rodapé da NFS-e devem respeitar 2000 caracteres e 2000 bytes no emissor, incluindo patrimônio e empenho. Reduza a mensagem fixa.');
        }
        if (filled($data['financial_category_id'] ?? null) && ! FinancialCategory::query()->where('company_id', $billing->company_id)
            ->where('is_active', true)->where('allow_receivable', true)->whereKey($data['financial_category_id'])->exists()) {
            $this->invalid('Categoria financeira inativa ou de outra empresa.');
        }
        if (filled($data['financial_account_id'] ?? null) && ! FinancialAccount::query()->where('company_id', $billing->company_id)
            ->where('is_active', true)->whereKey($data['financial_account_id'])->exists()) {
            $this->invalid('Conta financeira inativa ou de outra empresa.');
        }
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['billing' => $message]);
    }
}
