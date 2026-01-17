<?php

namespace App\Services\Address\Actions;

use App\Exceptions\DomainValidationException;
use App\Models\Address;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class CreateAddressAction
{
    public function __construct(
        private int $companyId,
        private int $partnerId,
        private int $createdBy,
    ) {}

    public function execute(array $input): Address
    {
        $data = [
            ...$this->validateInput($input),
            'company_id' => $this->companyId,
            'partner_id' => $this->partnerId,
            'created_by' => $this->createdBy,
        ];

        try {
            return Address::create($data);
        } catch (QueryException $e) {
            if ($e->getCode() === '23000') {
                throw new DomainValidationException([
                    'address' => ['Endereço já cadastrado para este parceiro nesta empresa'],
                ]);
            }

            throw $e;
        }

        return $result;
    }

    private function validateInput(array $input): array
    {
        $validator = Validator::make($input, [
            'street'        => 'required|string|max:150',
            'number'        => 'required|string|max:20',
            'complement'    => 'nullable|string|max:150',
            'neighborhood'  => 'nullable|string|max:100',
            'city'          => 'required|string|max:100',
            'state'         => ['required', Rule::in([
                'AC',
                'AL',
                'AP',
                'AM',
                'BA',
                'CE',
                'DF',
                'ES',
                'GO',
                'MA',
                'MT',
                'MS',
                'MG',
                'PA',
                'PB',
                'PR',
                'PE',
                'PI',
                'RJ',
                'RN',
                'RS',
                'RO',
                'RR',
                'SC',
                'SP',
                'SE',
                'TO'
            ])],
            'postal_code'   => ['required', 'regex:/^\d{5}-?\d{3}$/'],
            'country'       => 'required|string|max:50',
            'city_code'     => 'nullable|integer',
        ], [
            'street.required'   => 'É obrigatório informar o campo rua.',
            'street.max'        => 'Tamanho máx. para o campo rua é de 150 caracteres',
            'number.required'   => 'É obrigatório informar o campo Número.',
            'number.max'        => 'Tamanho máx. para o campo número é de 20 caracteres.',
            'complement.max'    => 'Tamanho máx. para o campo complemento é de 150 caracteres.',
            'neighborhood.max'  => 'Tamanho máx. para o campo bairro é de 100 caracteres.',
            'city.required'     => 'É obrigatório informar o campo cidade',
            'city.max'          => 'Tamanho máx. para o campo cidade é de 100 caracteres.',
            'state.required'    => 'É obrigatório informar o campo estado.',
            'state.size'        => 'O campo estado deve conter 02 caracteres.',
            'country.required'  => 'É obrigatório informar o campo país.',
            'country.max'       => 'Tamanho máx. para o campo país é de 50 caracteres.',
            'postal_code.required'  => 'É obrigatório informar o campo CEP.',
            'city_code.integer'     => 'Tipo de valor é inválido para o campo Cód. Cidade',
        ]);

        if ($validator->fails()) {
            
            Log::error(__METHOD__ . '@' . __LINE__, [
                'message' => 'Erro de validação ao validar dados para criação de endereço',
                'errors'  => $validator->errors()->toArray(),
                'input'   => $input,
            ]);

            throw new DomainValidationException(
                $validator->errors()->toArray()
            );
        }

        return $validator->validated();
    }
}
