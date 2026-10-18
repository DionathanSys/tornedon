<?php

namespace App\Services\Financial\Banking\Providers;

use App\Models\BankAccountConnection;
use App\Models\BankSlip;
use App\Models\CompanyPartner;
use App\Models\PixCharge;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class IntegraBancosPayloadBuilder
{
    public function build(BankSlip|PixCharge $charge): array
    {
        if ($charge->request_snapshot !== null) {
            return $charge->request_snapshot;
        }
        $connection = $charge->connection;
        $kind = $charge instanceof BankSlip ? 'boleto' : 'pix';
        $profile = $this->profile($connection);
        if (! array_key_exists($kind, $profile)) {
            throw ValidationException::withMessages(['bank' => ['A modalidade não consta no contrato deste banco.']]);
        }
        $installment = $charge->installment;
        $customer = $installment?->accountReceivable?->customer;
        $company = $connection->company;
        if (! $customer || (int) $charge->company_id !== (int) $connection->company_id
            || (int) $installment->company_id !== (int) $connection->company_id) {
            throw ValidationException::withMessages(['company_id' => ['Cobrança sem pagador ou com empresa divergente.']]);
        }
        // Do not use the partner's cross-company fallback address/contact.
        $pivot = CompanyPartner::query()->where('company_id', $company->id)->where('partner_id', $customer->id)->first();
        $address = $pivot?->addresses()->latest('id')->first();
        $contact = $customer->contacts()->where('company_partner.company_id', $company->id)->where('contacts.is_active', true)->latest('contacts.id')->first();
        $settings = (array) data_get($connection->settings, $kind === 'boleto' ? 'cobranca' : 'pix', []);
        $payment = (array) ($settings['pagamento'] ?? []);
        $payment['valor'] = number_format((float) $charge->amount, 2, '.', '');
        if ($kind === 'boleto' || ($profile['pix']['due_date'] ?? false)) {
            $payment['data_vencimento'] = $charge->due_date?->toDateString();
        } else {
            unset($payment['data_vencimento']);
        }
        if ($kind === 'pix' && ($profile['pix']['key'] ?? false)) {
            $payment['chave_pix'] = $payment['chave_pix'] ?? $connection->financialAccount?->pix_key;
        }
        $document = preg_replace('/\D/', '', (string) $customer->document_number);
        $payload = [
            'codigo_banco' => (string) $connection->bank->code,
            'numero' => (string) $charge->id,
            'identificacao' => $charge->provider_identification,
            'pagamento' => $payment,
            'pagador' => array_filter([
                strlen($document) === 14 ? 'cnpj' : 'cpf' => $document,
                'nome' => $customer->name, 'email' => $contact?->email,
                'telefone' => $contact?->phone ? preg_replace('/\D/', '', $contact->phone) : null,
                'endereco' => $address ? array_filter([
                    'logradouro' => $address->street, 'numero' => $address->number, 'complemento' => $address->complement,
                    'bairro' => $address->neighborhood, 'nome_municipio' => $address->city, 'uf' => $address->state,
                    'cep' => preg_replace('/\D/', '', (string) $address->postal_code),
                ], fn ($value) => $value !== null && $value !== '') : null,
            ], fn ($value) => $value !== null && $value !== ''),
        ];
        if ($kind === 'boleto') {
            if (! empty($settings['conta'])) {
                $payload['conta'] = (array) $settings['conta'];
            }
            if (! empty($settings['beneficiario'])) {
                $payload['beneficiario'] = (array) $settings['beneficiario'];
            }
        } else {
            unset($payload['numero'], $payload['pagador']['endereco'], $payload['pagador']['telefone']);
            if (! ($profile['pix']['email'] ?? false)) {
                unset($payload['pagador']['email']);
            }
        }
        $rules = [
            'identificacao' => ['required', 'string', 'max:60'],
            'pagamento.valor' => ['required', 'numeric', 'gt:0'],
            'pagador.nome' => ['required', 'string', 'max:255'],
            'pagador.cpf' => ['required_without:pagador.cnpj', 'digits:11'],
            'pagador.cnpj' => ['required_without:pagador.cpf', 'digits:14'],
            'pagador.email' => ['sometimes', 'email'],
            'pagador.endereco.cep' => ['sometimes', 'digits:8'],
            'pagador.endereco.uf' => ['sometimes', 'regex:/^[A-Z]{2}$/'],
        ];
        $required = $kind === 'boleto' ? $profile['boleto'] : [];
        if ($kind === 'boleto' || ($profile['pix']['due_date'] ?? false)) {
            $rules['pagamento.data_vencimento'] = ['required', 'date_format:Y-m-d'];
        }
        if ($kind === 'pix') {
            foreach (['key' => 'pagamento.chave_pix', 'key_type' => 'pagamento.tipo_chave_pix', 'email' => 'pagador.email'] as $flag => $field) {
                if ($profile['pix'][$flag] ?? false) {
                    $required[] = $field;
                }
            }
        }
        foreach ($required as $field) {
            $rules[$field] = array_unique(['required', 'string', ...array_diff($rules[$field] ?? [], ['sometimes', 'nullable'])]);
        }
        foreach (['desconto', 'mora', 'multa'] as $group) {
            if (isset($payment[$group])) {
                $rules["pagamento.{$group}.valor"] = ['required', 'numeric', 'min:0'];
                if (isset($payment[$group]['data'])) {
                    $rules["pagamento.{$group}.data"] = ['required', 'date_format:Y-m-d'];
                }
            }
        }
        if ($kind === 'boleto') {
            $code = (string) (int) $connection->bank->code;
            $groups = match ($code) {
                '461' => ['desconto' => ['tipo', 'dias'], 'multa' => ['tipo']],
                '1' => ['desconto' => ['tipo', 'data'], 'mora' => ['tipo'], 'multa' => ['tipo', 'data']],
                '403' => ['desconto' => ['tipo', 'data'], 'multa' => ['tipo']],
                '364', '77' => ['desconto' => ['tipo', 'dias'], 'mora' => ['tipo'], 'multa' => ['tipo']],
                '341' => ['desconto' => ['codigo', 'tipo', 'data'], 'mora' => ['codigo', 'tipo', 'data'], 'multa' => ['codigo', 'tipo']],
                '33' => ['desconto' => ['tipo']],
                '756' => ['desconto' => ['tipo'], 'mora' => ['tipo'], 'multa' => ['tipo']],
                '748' => ['desconto' => ['tipo', 'data'], 'mora' => ['tipo']],
                default => [],
            };
            foreach ($groups as $group => $fields) {
                if (! isset($payment[$group])) {
                    continue;
                }
                foreach ($fields as $field) {
                    $rules["pagamento.{$group}.{$field}"] = $field === 'data'
                        ? ['required', 'date_format:Y-m-d'] : ['required', 'string'];
                }
            }
            $optionalAddresses = match ($code) {
                '461' => ['logradouro', 'cep'], '403' => ['logradouro', 'nome_municipio', 'uf', 'cep'],
                '748' => ['logradouro'], default => [],
            };
            if (isset($payload['pagador']['endereco'])) {
                foreach ($optionalAddresses as $field) {
                    $rules['pagador.endereco.'.$field] = ['required', 'string', ...array_diff($rules['pagador.endereco.'.$field] ?? [], ['sometimes', 'nullable'])];
                }
            }
            if (isset($payload['beneficiario'])) {
                $rules['beneficiario.nome'] = ['required', 'string'];
                $fields = match ($code) {
                    '403', '364', '77' => ['logradouro', 'nome_municipio', 'uf', 'cep'],
                    '341' => ['logradouro', 'bairro', 'nome_municipio', 'uf', 'cep'],
                    '748' => ['logradouro'], default => [],
                };
                foreach ($fields as $field) {
                    $rules['beneficiario.endereco.'.$field] = ['required', 'string'];
                }
            }
        }
        Validator::make($payload, $rules, ['required' => 'Preencha :attribute para emitir nesta conexão bancária.'])->validate();

        return $payload;
    }

    public function assertOperation(BankAccountConnection $connection, string $operation): void
    {
        if (! ($this->profile($connection)[$operation] ?? false)) {
            throw ValidationException::withMessages(['operation' => ['Operação não disponível no contrato deste banco.']]);
        }
    }

    private function profile(BankAccountConnection $connection): array
    {
        $code = (string) (int) $connection->bank->code;
        $profile = config('integrabancos-contracts.'.$code);
        if (! is_array($profile)) {
            throw ValidationException::withMessages(['bank' => ['Banco sem contrato local validado para IntegraBancos.']]);
        }

        return $profile;
    }
}
