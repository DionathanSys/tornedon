<?php

namespace App\Support\Financial;

use App\Models\AccountPayable;
use App\Models\AccountReceivable;
use App\Models\FiscalDocument;
use App\Models\Invoice;
use App\Models\Partner;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class AccountEditGuard
{
    public static function hasExternalCharges(AccountReceivable $account): bool
    {
        return $account->installments()->where(function (Builder $query): void {
            $query->whereHas('bankSlips', fn (Builder $q) => $q->whereNotIn('status', ['canceled', 'registration_failed']))
                ->orWhereHas('pixCharges', fn (Builder $q) => $q->whereNotIn('status', ['canceled', 'registration_failed', 'expired']));
        })->exists();
    }

    public static function canChangeCounterparty(AccountPayable|AccountReceivable $account): bool
    {
        return ! $account->payments()->exists()
            && $account->status?->value !== 'cancelled'
            && ! $account->fiscal_document_id
            && ! $account->getAttribute('invoice_id')
            && ! ($account instanceof AccountPayable && ($account->purchaseClosing()->exists() || $account->companyCardStatements()->exists()))
            && ! ($account instanceof AccountReceivable && ($account->productionRequests()->exists() || self::hasExternalCharges($account)));
    }

    /** Called with the account locked, in the same transaction as the update. */
    public static function validate(AccountPayable|AccountReceivable $account, array $data): void
    {
        if ($account->payments()->exists()) {
            throw ValidationException::withMessages(['account' => 'Os dados da conta não podem ser alterados após uma baixa. Utilize as ações das parcelas.']);
        }
        foreach (['company_id', 'invoice_id', 'fiscal_document_id'] as $field) {
            if (array_key_exists($field, $data) && (string) $data[$field] !== (string) $account->getAttribute($field)) {
                throw ValidationException::withMessages([$field => 'A empresa e a origem da conta não podem ser alteradas.']);
            }
        }
        $candidate = clone $account;
        $candidate->fill(array_intersect_key($data, array_flip($account->getFillable())));
        if ($candidate->isDirty(['due_date', 'due_amount'])) {
            throw ValidationException::withMessages(['due_amount' => 'Valor e vencimento da conta são calculados pelas parcelas. Altere a parcela correspondente.']);
        }

        $field = $account instanceof AccountPayable ? 'supplier_id' : 'customer_id';
        // A null explicitly sent is different from an omitted field.
        $changed = array_key_exists($field, $data) && (string) $data[$field] !== (string) $account->{$field};
        $changed = $changed || (array_key_exists('manual_counterparty_name', $data)
            && trim((string) $data['manual_counterparty_name']) !== trim((string) $account->manual_counterparty_name));
        if ($changed) {
            if (! self::canChangeCounterparty($account)) {
                throw ValidationException::withMessages([$field => 'O parceiro não pode ser alterado em uma conta com origem, baixa, cancelamento ou cobrança vinculada.']);
            }
            Validator::make(['counterparty_change_reason' => trim((string) ($data['counterparty_change_reason'] ?? ''))],
                ['counterparty_change_reason' => 'required|string|min:5|max:1000'],
                ['counterparty_change_reason.required' => 'Informe o motivo da correção do parceiro.'])->validate();
        }
        if ($account instanceof AccountReceivable && self::hasExternalCharges($account)) {
            if ($candidate->isDirty(['due_date', 'due_amount', 'payment_method', 'card_payment_profile_id', 'payment_date', 'expected_settlement_date'])) {
                throw ValidationException::withMessages(['account' => 'Trate a cobrança bancária vinculada antes de alterar as condições da conta.']);
            }
        }
    }

    public static function validateRelationships(array $data, string $partnerField): void
    {
        $companyId = (int) ($data['company_id'] ?? 0);
        if (filled($data[$partnerField] ?? null) && ! Partner::query()->whereKey($data[$partnerField])
            ->whereHas('companies', fn (Builder $q) => $q->where('companies.id', $companyId)->where('company_partner.is_active', true))->exists()) {
            throw ValidationException::withMessages([$partnerField => 'Selecione um parceiro com vínculo ativo nesta empresa.']);
        }
        foreach (['invoice_id' => Invoice::class, 'fiscal_document_id' => FiscalDocument::class] as $field => $model) {
            if (blank($data[$field] ?? null)) {
                continue;
            }
            $origin = $model::query()->where('company_id', $companyId)->find($data[$field]);
            if (! $origin || (filled($origin->customer_id) && (int) $origin->customer_id !== (int) ($data[$partnerField] ?? 0))) {
                throw ValidationException::withMessages([$field => 'A origem deve pertencer à empresa e ao parceiro da conta.']);
            }
        }
    }
}
