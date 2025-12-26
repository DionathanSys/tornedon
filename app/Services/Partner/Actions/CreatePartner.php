<?php

namespace App\Services\Partner\Actions;

use App\Models\Partner;
use App\Enum;
use App\Traits\HandlesActionResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class CreatePartner
{
    use HandlesActionResponse;

    private array $fillableFields;

    public function __construct()
    {
        $this->fillableFields = (new Partner())->getFillable();
    }

    public function execute(array $data): ?Partner
    {
        $this->validate($data);

        if ($this->hasError()) return null;

        if (($partner = $this->exists($data))) {
            $this->setSuccess();
            Log::info(__METHOD__ . '@' . __LINE__, [
                'message'           => 'Parceiro já cadastrado',
                'document_number'   => $data['document_number'],
            ]);
            return $partner;
        }

        $data = Arr::only($data, $this->fillableFields);        
        $partner = Partner::create($data);
        $this->setSuccess();
        return $partner;
    }

    private function exists(array $data): ?Partner
    {
        return Partner::where('document_number', $data['document_number'])->first();
    }

    private function validate(array $data): void
    {
        $validate = Validator::make($data, [
            'name'                  => 'required|string|max:255',
            'type'                  => 'required|array|min:1',
            'type.*'                => 'required|string|in:'.implode(Enum\Partner\Type::toSelectArray()),
            'document_type'         => 'required|string|in:cnpj,cpf',
            'document_number'       => 'required|string|min:14|max:18',
            'is_active'             => 'required|boolean',
            'state_tax_id'          => 'nullable|string|max:50',
            'state_tax_indicator'   => 'nullable|string|max:50',
            'municipal_tax_id'      => 'nullable|string|max:50',
            'created_by'            => 'required|integer|exists:users,id',
            'updated_by'            => 'required|integer|exists:users,id',
        ]);

        if ($validate->fails()) {
            $this->setError($validate->errors()->toArray());
            Log::error(__METHOD__ . '@' . __LINE__, [
                'message'   => 'Falha de validação',
                'errors'    => $validate->errors()->toArray(),
            ]);
        }

        Log::info(__METHOD__ . '@' . __LINE__, [
            'message'   => 'Validação realizada com sucesso',
        ]);
        
        return;
    }
}
