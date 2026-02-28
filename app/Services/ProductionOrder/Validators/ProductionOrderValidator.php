<?php

namespace App\Services\ProductionOrder\Validators;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ProductionOrderValidator
{
    public static function validate(array $data): array
    {
        $validator = Validator::make($data, [
            'partner_id'                        => 'required|exists:partners,id',
            'company_id'                        => 'required|exists:companies,id',
            'quote_id'                          => 'nullable|exists:quotes,id',
            'priority'                          => 'required|in:low,normal,high,urgent',
            'destination_type'                  => 'required|in:stock,direct_delivery',
            'observations'                      => 'nullable|string',
            'assigned_operator'                 => 'nullable|exists:users,id',
            'assigned_machine'                  => 'nullable|string',
            'items'                             => 'required|array|min:1',
            'items.*.product_id'                => 'nullable|exists:products,id',
            'items.*.description'               => 'nullable|string',
            'items.*.quantity'                  => 'required|numeric|min:0.001',
            'items.*.unit_of_measure'           => 'required|string',
            'items.*.technical_specifications'  => 'nullable|array',
        ], [
            'partner_id.required'       => 'Cliente é obrigatório',
            'partner_id.exists'         => 'Cliente não encontrado',
            'company_id.required'       => 'Empresa é obrigatória',
            'company_id.exists'         => 'Empresa não encontrada',
            'priority.required'         => 'Prioridade é obrigatória',
            'destination_type.required' => 'Tipo de destino é obrigatório',
            'items.required'            => 'É necessário adicionar ao menos um item',
            'items.min'                 => 'É necessário adicionar ao menos um item',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $validator->validated();
    }
}
