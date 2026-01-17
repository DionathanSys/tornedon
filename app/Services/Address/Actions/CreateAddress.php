<?php

namespace App\Services\Address\Actions;

use App\Models\Address;
use App\Models\User;
use App\Traits\HandlesActionResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class CreateAddress
{
    use HandlesActionResponse;

    private User $user;
    private array $fillableFields;
    private array $dataValidated;

    public function __construct(private int $userId)
    {
        $this->user = User::query()->findOrFail($userId);
    }

    public function execute(array $data): ?Address
    {
        Log::debug('Dados recebidos - ' . __METHOD__ . '@' . __LINE__, [
            'data'      => $data,
            'userId'    => $this->userId,
        ]);

        $this->validate($data);

        if ($this->hasError()) return null;

        if ($this->exists($data)) {
            $this->setError('Endereço já possui cadastro para Parceiro/Empresa');
            return null;
        }

        $this->dataValidated['created_by'] = $this->userId;

        $result = Address::create($this->dataValidated);
        return $result;
    }

    private function exists(array $data): bool
    {
        return Address::query()
            ->where('postal_code', $data['postal_code'])
            ->where('street', $data['street'])
            ->where('number', $data['number'])
            ->where('partner_id', $data['partner_id'])
            ->where('company_id', $data['company_id'])
            ->exists();
    }

    private function validate(array $data): void
    {
        //TODO: Melhorar validação de 'state' e 'postal_code'
        $validator = Validator::make($data, [
            'company_id'    => ['required', Rule::exists('companies', 'id')
                ->whereIn('id', $this->user->companies()->pluck('id'))],
            'partner_id' => ['required', Rule::exists('company_partner', 'partner_id')
                ->where('company_id', $data['company_id'])],
            'street'        => 'required|string|max:150',
            'number'        => 'required|string|max:20',
            'complement'    => 'nullable|string|max:150',
            'neighborhood'  => 'nullable|string|max:100',
            'city'          => 'required|string|max:100',
            'state'         => 'required|string|size:2',
            'country'       => 'required|string|max:50',
            'postal_code'   => 'required|string|max:10',
            'city_code'     => 'nullable|integer',
        ], [
            'company.required'  => 'Empresa não esta sendo informada.',
            'partner.required'  => 'Parceiro não esta sendo informado.',
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
            'city_code.required'    => 'Tipo de valor inválido para o campo Cód. Cidade',
        ]);

        if ($validator->fails()) {
            $this->setError('Falha de validação dos dados', $validator->errors()->all());
            Log::error(__METHOD__ . '@' . __LINE__, [
                'message'   => 'Falha de validação dos dados',
                'errors'    => $validator->errors()->toArray(),
                'data'      => $data,
            ]);
            return;
        }

        $this->dataValidated = $validator->validated();

        return;
    }
}
