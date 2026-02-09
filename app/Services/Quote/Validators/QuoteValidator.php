<?php

namespace App\Services\Quote\Validators;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class QuoteValidator
{
    public static function validate(array $data): array
    {
        $validator = Validator::make($data, [
            'partner_id' => 'required|exists:partners,id',
            'company_id' => 'required|exists:companies,id',
            'description' => 'nullable|string',
            'valid_until' => 'nullable|date|after:today',
            'observations' => 'nullable|string',
            'customer_observations' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'nullable|exists:products,id',
            'items.*.description' => 'required|string',
            'items.*.quantity' => 'required|numeric|min:0.001',
            'items.*.unit_of_measure' => 'required|string',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.discount_percentage' => 'nullable|numeric|min:0|max:100',
            'items.*.discount_amount' => 'nullable|numeric|min:0',
            'items.*.technical_specifications' => 'nullable|array',
            'items.*.estimated_production_hours' => 'nullable|numeric|min:0',
            'items.*.material_cost' => 'nullable|numeric|min:0',
            'items.*.labor_cost' => 'nullable|numeric|min:0',
        ], [
            'partner_id.required' => 'Cliente é obrigatório',
            'partner_id.exists' => 'Cliente não encontrado',
            'company_id.required' => 'Empresa é obrigatória',
            'company_id.exists' => 'Empresa não encontrada',
            'items.required' => 'É necessário adicionar ao menos um item',
            'items.min' => 'É necessário adicionar ao menos um item',
            'items.*.description.required' => 'Descrição do item é obrigatória',
            'items.*.quantity.required' => 'Quantidade é obrigatória',
            'items.*.quantity.min' => 'Quantidade deve ser maior que zero',
            'items.*.unit_of_measure.required' => 'Unidade de medida é obrigatória',
            'items.*.unit_price.required' => 'Preço unitário é obrigatório',
            'items.*.unit_price.min' => 'Preço unitário deve ser maior ou igual a zero',
            'valid_until.after' => 'Data de validade deve ser futura',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $validator->validated();
    }

    public static function validateApproval(array $data): void
    {
        $validator = Validator::make($data, [
            'status' => 'required|in:sent',
        ], [
            'status.in' => 'Apenas orçamentos enviados podem ser aprovados',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
    }
}
