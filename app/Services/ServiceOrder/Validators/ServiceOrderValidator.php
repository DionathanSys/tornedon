<?php

namespace App\Services\ServiceOrder\Validators;

use App\Enum\Payment\Condition as PaymentCondition;
use App\Enum\Payment\Method as PaymentMethod;
use App\Enum\ServiceOrder\State;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ServiceOrderValidator
{
    /**
     * Valida dados para criação de ordem de serviço.
     *
     * @param array $data Dados a validar
     * @return array Retorna dados validados
     * @throws ValidationException Se a validação falhar
     */
    public static function validateCreate(array $data): array
    {
        $statusValues = array_map(fn($status) => $status->value, State::cases());

        $rules = [
            'number'                    => [
                'required',
                'string',
                'max:50',
                Rule::unique('service_orders', 'number')->where('company_id', $data['company_id'] ?? null),
            ],
            'customer_id'               => 'required|integer|exists:partners,id',
            'order_date'                => 'required|date',
            'scheduled_date'            => 'nullable|date|after_or_equal:order_date',
            'limit_date'                => 'nullable|date|after_or_equal:order_date',
            'completion_date'           => 'nullable|date',
            'status'                    => ['required', Rule::in($statusValues)],
            'priority'                  => 'required|string|max:20',
            'type'                      => 'required|string|max:50',
            'solution'                  => 'nullable|string',
            'equipment_id'              => 'nullable|integer|exists:equipments,id',
            'location'                  => 'nullable|string|max:255',
            'customer_observations'     => 'nullable|string',
            'technician_observations'   => 'nullable|string',
            'estimated_hours'           => 'nullable|numeric|min:0',
            'actual_hours'              => 'nullable|numeric|min:0',
            'travel_value'              => 'nullable|numeric|min:0',
            'discount_amount'           => 'nullable|numeric|min:0',
            'payment_method'            => ['nullable', Rule::enum(PaymentMethod::class)],
            'payment_condition'         => 'nullable|string|max:100',
            'technician_id'             => 'nullable|integer|exists:users,id',
            'supervisor_id'             => 'nullable|integer|exists:users,id',
            'salesperson_id'            => 'nullable|integer|exists:users,id',
            'warranty_expires_at'       => 'nullable|date',
            'requires_approval'         => 'nullable|boolean',
            'approved_by_customer'      => 'nullable|boolean',
            'approved_at'               => 'nullable|date',
            'customer_rating'           => 'nullable|numeric|min:0|max:5',
            'customer_feedback'         => 'nullable|string',
            'invoice_id'                => 'nullable|integer|exists:invoices,id',
            'additional_info'           => 'nullable|array',
        ];

        $messages = self::messages();

        return Validator::make($data, $rules, $messages)->validate();
    }

    /**
     * Valida dados para atualização de ordem de serviço.
     *
     * @param array $data Dados a validar
     * @param int|null $serviceOrderId ID da ordem de serviço sendo atualizada
     * @param int|null $companyId ID da empresa
     * @return array Retorna dados validados
     * @throws ValidationException Se a validação falhar
     */
    public static function validateUpdate(array $data, ?int $serviceOrderId = null, ?int $companyId = null): array
    {
        $statusValues = array_map(fn($status) => $status->value, State::cases());

        $rules = [
            'customer_id'               => 'sometimes|required|integer|exists:partners,id',
            'order_date'                => 'sometimes|required|date',
            'scheduled_date'            => 'nullable|date',
            'limit_date'                => 'nullable|date',
            'completion_date'           => 'nullable|date',
            'status'                    => ['sometimes', 'required', Rule::in($statusValues)],
            'priority'                  => 'sometimes|required|string|max:20',
            'type'                      => 'sometimes|required|string|max:50',
            'solution'                  => 'nullable|string',
            'equipment_id'              => 'nullable|integer|exists:equipments,id',
            'location'                  => 'nullable|string|max:255',
            'customer_observations'     => 'nullable|string',
            'technician_observations'   => 'nullable|string',
            'estimated_hours'           => 'nullable|numeric|min:0',
            'actual_hours'              => 'nullable|numeric|min:0',
            'travel_value'              => 'nullable|numeric|min:0',
            'discount_amount'           => 'nullable|numeric|min:0',
            'payment_method'            => ['nullable', Rule::enum(PaymentMethod::class)],
            'payment_condition'         => ['nullable', Rule::enum(PaymentCondition::class)],
            'technician_id'             => 'nullable|integer|exists:users,id',
            'supervisor_id'             => 'nullable|integer|exists:users,id',
            'salesperson_id'            => 'nullable|integer|exists:users,id',
            'warranty_expires_at'       => 'nullable|date',
            'requires_approval'         => 'nullable|boolean',
            'approved_by_customer'      => 'nullable|boolean',
            'approved_at'               => 'nullable|date',
            'customer_rating'           => 'nullable|numeric|min:0|max:5',
            'customer_feedback'         => 'nullable|string',
            'invoice_id'                => 'nullable|integer|exists:invoices,id',
            'additional_info'           => 'nullable|array',
        ];

        // Adiciona validação de number apenas se o campo estiver presente nos dados
        if (isset($data['number']) && $companyId) {
            $rules['number'] = [
                'sometimes',
                'required',
                'string',
                'max:50',
                Rule::unique('service_orders', 'number')
                    ->where('company_id', $companyId)
                    ->ignore($serviceOrderId),
            ];
        }

        $messages = self::messages();

        return Validator::make($data, $rules, $messages)->validate();
    }

    /**
     * Mensagens de validação compartilhadas.
     */
    private static function messages(): array
    {
        return [
            'number.required'               => 'É obrigatório informar o número da ordem de serviço',
            'number.unique'                 => 'Já existe uma ordem de serviço com este número',
            'number.max'                    => 'O número da OS não pode ter mais de 50 caracteres',
            'customer_id.required'          => 'É obrigatório informar o cliente',
            'customer_id.exists'            => 'O cliente informado não existe',
            'company_id.required'           => 'É obrigatório informar a empresa',
            'company_id.exists'             => 'A empresa informada não existe',
            'order_date.required'           => 'É obrigatório informar a data da ordem',
            'order_date.date'               => 'A data da ordem deve ser uma data válida',
            'scheduled_date.date'           => 'A data agendada deve ser uma data válida',
            'scheduled_date.after_or_equal' => 'A data agendada deve ser igual ou posterior à data da ordem',
            'limit_date.date'               => 'A data limite deve ser uma data válida',
            'limit_date.after_or_equal'     => 'A data limite deve ser igual ou posterior à data da ordem',
            'completion_date.date'          => 'A data de conclusão deve ser uma data válida',
            'status.required'               => 'É obrigatório informar o status',
            'status.in'                     => 'O status informado é inválido',
            'priority.required'             => 'É obrigatório informar a prioridade',
            'priority.max'                  => 'A prioridade não pode ter mais de 20 caracteres',
            'type.required'                 => 'É obrigatório informar o tipo de serviço',
            'type.max'                      => 'O tipo de serviço não pode ter mais de 50 caracteres',
            'equipment_id.exists'           => 'O equipamento informado não existe',
            'location.max'                  => 'O local não pode ter mais de 255 caracteres',
            'estimated_hours.numeric'       => 'As horas estimadas devem ser um número',
            'estimated_hours.min'           => 'As horas estimadas não podem ser negativas',
            'actual_hours.numeric'          => 'As horas reais devem ser um número',
            'actual_hours.min'              => 'As horas reais não podem ser negativas',
            'travel_value.numeric'          => 'O valor de deslocamento deve ser um número',
            'travel_value.min'              => 'O valor de deslocamento não pode ser negativo',
            'discount_amount.numeric'       => 'O desconto deve ser um número',
            'discount_amount.min'           => 'O desconto não pode ser negativo',
            'technician_id.exists'          => 'O técnico informado não existe',
            'supervisor_id.exists'          => 'O supervisor informado não existe',
            'salesperson_id.exists'         => 'O vendedor informado não existe',
            'warranty_expires_at.date'      => 'A data de expiração da garantia deve ser uma data válida',
            'requires_approval.boolean'     => 'O campo requer aprovação deve ser verdadeiro ou falso',
            'approved_by_customer.boolean'  => 'O campo aprovado pelo cliente deve ser verdadeiro ou falso',
            'approved_at.date'              => 'A data de aprovação deve ser uma data válida',
            'customer_rating.numeric'       => 'A avaliação do cliente deve ser um número',
            'customer_rating.min'           => 'A avaliação do cliente deve ser no mínimo 0',
            'customer_rating.max'           => 'A avaliação do cliente deve ser no máximo 5',
            'invoice_id.exists'             => 'A fatura informada não existe',
            'additional_info.array'         => 'As informações adicionais devem ser um array',
        ];
    }
}
