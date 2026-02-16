<?php

namespace App\Services\ProductTax\Validators;

use App\Enum\Product\Origin;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductTaxValidator
{
    public static function validateCreate(array $data): array
    {
        $originValues = array_map(fn($o) => $o->value, Origin::cases());

        $rules = [
            'product_id'    => [
                'required',
                'integer',
                'exists:products,id',
                Rule::unique('product_taxes', 'product_id'),
            ],
            'product_origin'=> ['nullable', Rule::in($originValues)],
            'ncm_code'      => 'nullable|string|min:2|max:8',
            'cest_code'     => 'nullable|string|max:9',
            'icms'          => 'nullable|array',
            'ipi'           => 'nullable|array',
            'pis'           => 'nullable|array',
            'cofins'        => 'nullable|array',
        ];

        $messages = [
            'product_id.required' => 'É obrigatório informar o produto',
            'product_id.exists'   => 'Produto informado não existe',
            'product_id.unique'   => 'Já existe um registro de imposto para este produto',
            'product_origin.in'   => 'Origem do produto inválida',
            'ncm_code.min'       => 'O código NCM deve ter no mínimo 2 caracteres',
            'ncm_code.max'       => 'O código NCM deve ter no máximo 8 caracteres',
            'cest_code.max'      => 'O código CEST deve ter no máximo 9 caracteres',
        ];

        Log::debug('Validando dados para criação de ProductTax', [
            'metodo' => __METHOD__ . '@' . __LINE__,
            'data' => $data,
        ]);

        return Validator::make($data, $rules, $messages)->validate();
    }

    public static function validateUpdate(array $data, ?int $productTaxId = null): array
    {
        $originValues = array_map(fn($o) => $o->value, Origin::cases());

        $rules = [
            'product_id'    => [
                'required',
                'integer',
                'exists:products,id',
                Rule::unique('product_taxes', 'product_id')->ignore($productTaxId),
            ],
            'product_origin'=> ['nullable', Rule::in($originValues)],
            'ncm_code'      => 'nullable|string|min:2|max:8',
            'cest_code'     => 'nullable|string|max:9',
            'icms'          => 'nullable|array',
            'ipi'           => 'nullable|array',
            'pis'           => 'nullable|array',
            'cofins'        => 'nullable|array',
        ];

        $messages = [
            'product_id.required' => 'É obrigatório informar o produto',
            'product_id.exists'   => 'Produto informado não existe',
            'product_id.unique'   => 'Já existe um registro de imposto para este produto',
            'product_origin.in'   => 'Origem do produto inválida',
            'ncm_code.min'       => 'O código NCM deve ter no mínimo 2 caracteres',
            'ncm_code.max'       => 'O código NCM deve ter no máximo 8 caracteres',
            'cest_code.max'      => 'O código CEST deve ter no máximo 9 caracteres',
        ];

        Log::debug('Validando dados para criação de ProductTax', [
            'metodo' => __METHOD__ . '@' . __LINE__,
            'data' => $data,
        ]);
        
        return Validator::make($data, $rules, $messages)->validate();
    }

    // Alias para compatibilidade com nomenclatura "edit"
    public static function validateEdit(array $data, ?int $productTaxId = null): array
    {
        return self::validateUpdate($data, $productTaxId);
    }
}
