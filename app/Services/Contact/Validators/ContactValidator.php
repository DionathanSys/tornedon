<?php

namespace App\Services\Contact\Validators;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ContactValidator
{
    /**
     * Valida dados de Contact
     * @param array $data Dados a validar
     * @param int $companyPartnerId ID do CompanyPartner
     * @param int|null $contactId ID do contato (para edição)
     * @return array Retorna dados validados
     * @throws ValidationException Se a validação falhar
     */
    public static function validate(array $data, int $companyPartnerId, ?int $contactId = null): array
    {
        $rules = [
            'email' => [
                'sometimes',
                'nullable',
                'email',
                'max:255',
                'required_if:notify,true',
                Rule::unique('contacts', 'email')
                    ->where('company_partner_id', $companyPartnerId)
                    ->ignore($contactId ?? null),
            ],
            'phone' => 'sometimes|nullable|string|max:255',
            'mobile' => 'sometimes|nullable|string|max:255',
            'notify' => 'boolean',
            'is_active' => 'boolean',
        ];
        
        $messages = [
            'email.email' => 'O e-mail informado não é válido.',
            'email.unique' => 'Este e-mail já está cadastrado para este parceiro.',
            'email.max' => 'O e-mail não pode ter mais de 255 caracteres.',
            'email.required_if' => 'O e-mail é obrigatório quando a opção de receber notificações está ativada.',
            'phone.max' => 'O telefone não pode ter mais de 255 caracteres.',
            'mobile.max' => 'O celular não pode ter mais de 255 caracteres.',
            'notify.boolean' => 'O campo notificação deve ser verdadeiro ou falso.',
            'is_active.boolean' => 'O campo ativo deve ser verdadeiro ou falso.',
        ];

        return Validator::make($data, $rules, $messages)->validate();
    }
}
