<?php

namespace App\Services\Quote\Validators;

use App\Enum\Payment\Condition;
use App\Enum\Payment\Method;
use App\Enum\Quote\Status;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class QuoteValidator
{
    /**
     * Valida dados para criação de orçamento.
     *
     * @param  array  $data  Dados a validar
     * @return array         Retorna dados validados
     * @throws ValidationException Se a validação falhar
     */
    public static function validateCreate(array $data): array
    {
        $validator = Validator::make($data, [
            'company_id'             => 'required|integer|exists:companies,id',
            'partner_id'             => 'required|integer|exists:partners,id',
            'payment_method'          => ['required', Rule::enum(Method::class)],
            'payment_condition'       => ['required', Rule::enum(Condition::class)],
            'description'            => 'nullable|string|max:1000',
            'valid_until'            => 'nullable|date|after:today',
            'observations'           => 'nullable|string|max:2000',
            'customer_observations'  => 'nullable|string|max:2000',
            'additional_info'        => 'nullable|array',
        ], [
            'company_id.required'    => 'A empresa é obrigatória.',
            'company_id.exists'      => 'Empresa não encontrada.',
            'payment_method.required' => 'O método de pagamento é obrigatório.',
            'payment_method.enum'     => 'Método de pagamento inválido.',
            'payment_condition.required' => 'A condição de pagamento é obrigatória.',
            'payment_condition.enum'     => 'Condição de pagamento inválida.',
            'partner_id.required'    => 'O cliente é obrigatório.',
            'partner_id.exists'      => 'Cliente não encontrado.',
            'description.max'        => 'A descrição não pode ter mais de 1000 caracteres.',
            'valid_until.after'      => 'A data de validade deve ser uma data futura.',
            'observations.max'       => 'As observações não podem ter mais de 2000 caracteres.',
            'customer_observations.max' => 'As observações do cliente não podem ter mais de 2000 caracteres.',
        ]);

        return $validator->validate();
    }

    /**
     * Valida dados para atualização de orçamento.
     *
     * @param  array  $data     Dados a validar
     * @param  int    $quoteId  ID do orçamento sendo atualizado
     * @return array            Retorna dados validados
     * @throws ValidationException Se a validação falhar
     */
    public static function validateUpdate(array $data, int $quoteId): array
    {
        $validator = Validator::make($data, [
            'partner_id'             => 'sometimes|required|integer|exists:partners,id',
            'payment_method'          => ['required', Rule::enum(Method::class)],
            'payment_condition'       => ['required', Rule::enum(Condition::class)],
            'description'            => 'nullable|string|max:1000',
            'valid_until'            => 'nullable|date',
            'observations'           => 'nullable|string|max:2000',
            'customer_observations'  => 'nullable|string|max:2000',
            'additional_info'        => 'nullable|array',
        ], [
            'partner_id.required'    => 'O cliente é obrigatório.',
            'partner_id.exists'      => 'Cliente não encontrado.',
            'payment_method.required' => 'O método de pagamento é obrigatório.',
            'payment_method.enum'     => 'Método de pagamento inválido.',
            'payment_condition.required' => 'A condição de pagamento é obrigatória.',
            'payment_condition.enum'     => 'Condição de pagamento inválida.',
            'description.max'        => 'A descrição não pode ter mais de 1000 caracteres.',
            'observations.max'       => 'As observações não podem ter mais de 2000 caracteres.',
            'customer_observations.max' => 'As observações do cliente não podem ter mais de 2000 caracteres.',
        ]);

        return $validator->validate();
    }

}
