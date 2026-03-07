<?php

namespace App\Services\Requisition\Validators;

use App\Enum\Payment\Condition as PaymentCondition;
use App\Enum\Payment\Method as PaymentMethod;
use App\Enum\Requisition\Status;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RequisitionValidator
{
    /**
     * Regras comuns de validação (campos compartilhados entre create e update).
     */
    private static function commonRules(): array
    {
        return [
            'quote_id'          => 'nullable|integer|exists:quotes,id',
            'service_order_id'  => 'nullable|integer|exists:service_orders,id',
            'payment_method'    => ['nullable', Rule::enum(PaymentMethod::class)],
            'payment_condition' => ['nullable', Rule::enum(PaymentCondition::class)],
            'observations'      => 'nullable|string',
            'delivery_address'  => 'nullable|string|max:500',
            'salesperson_id'    => 'nullable|integer|exists:users,id',
            'invoice_id'        => 'nullable|integer|exists:invoices,id',
            'invoiced_at'       => 'nullable|date',
            'equipment_id'      => 'nullable|integer|exists:equipments,id',
            'stock_consumed'    => 'nullable|boolean',
            'additional_info'   => 'nullable|array',
        ];
    }

    /**
     * Mensagens de validação compartilhadas.
     */
    private static function messages(): array
    {
        return [
            'number.required'              => 'O número da requisição é obrigatório.',
            'number.unique'                => 'Já existe uma requisição com este número para a empresa.',
            'company_id.required'          => 'A empresa é obrigatória.',
            'company_id.exists'            => 'Empresa não encontrada.',
            'customer_id.required'         => 'O cliente é obrigatório.',
            'customer_id.exists'           => 'Cliente não encontrado.',
            'service_order_id.exists'      => 'Ordem de serviço não encontrada.',
            'sale_date.required'           => 'A data da venda é obrigatória.',
            'sale_date.date'               => 'A data da venda deve ser uma data válida.',
            'status.required'              => 'O status é obrigatório.',
            'status.in'                    => 'Status inválido.',
            'delivery_date.after_or_equal' => 'A data de entrega deve ser igual ou posterior à data da venda.',
            'salesperson_id.exists'        => 'Vendedor não encontrado.',
            'invoice_id.exists'            => 'Fatura não encontrada.',
            'equipment_id.exists'          => 'Equipamento não encontrado.',
        ];
    }

    /**
     * Valida dados para criação de requisição.
     *
     * @param  array  $data  Dados a validar
     * @return array         Retorna dados validados
     * @throws ValidationException Se a validação falhar
     */
    public static function validateCreate(array $data): array
    {
        $statusValues = array_map(fn ($s) => $s->value, Status::cases());

        $rules = array_merge(self::commonRules(), [
            'number'        => [
                'required',
                'string',
                'max:50',
                Rule::unique('requisitions', 'number')->where('company_id', $data['company_id'] ?? null),
            ],
            'company_id'    => 'required|integer|exists:companies,id',
            'customer_id'   => 'required|integer|exists:partners,id',
            'sale_date'     => 'required|date',
            'status'        => ['required', Rule::enum(Status::class)],
            'delivery_date' => 'nullable|date|after_or_equal:sale_date',
        ]);

        return Validator::make($data, $rules, self::messages())->validate();
    }

    /**
     * Valida dados para atualização de requisição.
     *
     * @param  array  $data           Dados a validar
     * @param  int    $requisitionId  ID da requisição sendo atualizada
     * @param  int    $companyId      ID da empresa da requisição
     * @return array                  Retorna dados validados
     * @throws ValidationException    Se a validação falhar
     */
    public static function validateUpdate(array $data, int $requisitionId, int $companyId): array
    {
        $statusValues = array_map(fn ($s) => $s->value, Status::cases());

        $rules = array_merge(self::commonRules(), [
            'number'        => [
                'sometimes',
                'required',
                'string',
                'max:50',
                Rule::unique('requisitions', 'number')
                    ->where('company_id', $companyId)
                    ->ignore($requisitionId),
            ],
            'company_id'    => 'sometimes|required|integer|exists:companies,id',
            'customer_id'   => 'sometimes|required|integer|exists:partners,id',
            'sale_date'     => 'sometimes|required|date',
            'status'        => ['sometimes', 'required', Rule::enum(Status::class)],
            'delivery_date' => 'nullable|date',
        ]);

        return Validator::make($data, $rules, self::messages())->validate();
    }
}
