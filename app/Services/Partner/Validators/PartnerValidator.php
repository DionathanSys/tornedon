<?php

namespace App\Services\Partner\Validators;

use App\Enum;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PartnerValidator
{
    /**
     * Valida dados para criação de Partner
     * @return array Retorna dados validados
     * @throws ValidationException Se a validação falhar
     */
    public function validateForCreate(array $data): array
    {
        return $this->validate($data, 'create');
    }

    /**
     * Valida dados para edição de Partner
     * @return array Retorna dados validados
     * @throws ValidationException Se a validação falhar
     */
    public function validateForUpdate(array $data, int $partnerId): array
    {
        return $this->validate($data, 'update', $partnerId);
    }

    /**
     * Executa validação
     * @return array Retorna dados validados
     * @throws ValidationException Se a validação falhar
     */
    private function validate(array $data, string $context = 'create', ?int $partnerId = null): array
    {
        $rules = $this->getRules($context, $partnerId, $data);
        $messages = $this->getMessages($context);

        return Validator::make($data, $rules, $messages)->validate();
    }

    /**
     * Define regras de validação
     */
    private function getRules(string $context, ?int $partnerId, array $data): array
    {
        $rules = [
            'name'                  => 'required|string|max:255',
            'document_type'         => 'required|string|in:cnpj,cpf',
            'document_number'       => [
                'required',
                'string',
                function ($attribute, $value, $fail) use ($data) {
                    if (($data['document_type'] ?? null) === 'cpf' && strlen($value) !== 14) {
                        $fail('O CPF deve conter exatamente 14 caracteres.');
                    }

                    if (($data['document_type'] ?? null) === 'cnpj' && strlen($value) !== 18) {
                        $fail('O CNPJ deve conter exatamente 18 caracteres.');
                    }
                },
            ],
            'state_tax_id'          => 'nullable|string|max:50',
            'state_tax_indicator'   => 'nullable|int|in:' . implode(',', array_map(fn($case) => $case->value, Enum\Tax\StateTaxIndicator::cases())),
            'municipal_tax_id'      => 'nullable|string|max:50',
        ];

        // Adicionar regra específica por contexto
        if ($context === 'update' && $partnerId) {
            $rules['document_number'][] = Rule::unique('partners', 'document_number')->ignore($partnerId);
            $rules['updated_by'] = 'required|integer|exists:users,id';
        } else {
            $rules['created_by'] = 'required|integer|exists:users,id';
        }

        return $rules;
    }

    /**
     * Define mensagens de validação
     */
    private function getMessages(string $context): array
    {
        $messages = [
            'name.required'             => 'O nome do parceiro é obrigatório.',
            'document_type.in'          => 'O tipo de documento informado é inválido.',
            'document_number.required'  => 'O número do documento é obrigatório.',
            'state_tax_id.max'          => 'A inscrição estadual deve ter no máximo 50 caracteres.',
            'municipal_tax_id.max'      => 'A inscrição municipal deve ter no máximo 50 caracteres.',
            'state_tax_indicator.in'    => 'O indicador de inscrição estadual informado é inválido.',
        ];

        if ($context === 'update') {
            $messages['updated_by.required'] = 'O usuário atualizador é obrigatório.';
            $messages['updated_by.exists'] = 'O usuário atualizador informado não existe.';
        } else {
            $messages['created_by.required'] = 'O usuário criador é obrigatório.';
            $messages['created_by.exists'] = 'O usuário criador informado não existe.';
        }

        return $messages;
    }
}
