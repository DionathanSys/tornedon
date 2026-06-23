<?php

namespace App\Services\WarrantyClaim\Validators;

use App\Enum\WarrantyClaim\CoverageType;
use App\Enum\WarrantyClaim\Responsibility;
use App\Enum\WarrantyClaim\Status;
use App\Enum\WarrantyClaim\SupplierDecision;
use App\Enum\WarrantyClaim\SupplierResolution;
use App\Enum\WarrantyClaim\Type;
use App\Models\FiscalDocument;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Requisition;
use App\Models\ServiceOrder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class WarrantyClaimValidator
{
    public static function validateCreate(array $data): array
    {
        return self::make($data)->validate();
    }

    public static function validateUpdate(array $data, int $warrantyClaimId, int $companyId): array
    {
        return self::make($data, $warrantyClaimId, $companyId)->validate();
    }

    private static function make(array $data, ?int $warrantyClaimId = null, ?int $companyId = null)
    {
        $resolvedCompanyId = $companyId ?? ($data['company_id'] ?? null);

        $validator = Validator::make($data, [
            'number' => [
                'required',
                'string',
                'max:50',
                Rule::unique('warranty_claims', 'number')
                    ->where('company_id', $resolvedCompanyId)
                    ->ignore($warrantyClaimId),
            ],
            'company_id' => 'required|integer|exists:companies,id',
            'type' => ['required', Rule::enum(Type::class)],
            'status' => ['required', Rule::enum(Status::class)],
            'customer_id' => 'required|integer|exists:partners,id',
            'supplier_id' => 'nullable|integer|exists:partners,id',
            'service_order_id' => 'nullable|integer|exists:service_orders,id',
            'origin_service_order_id' => 'nullable|integer|exists:service_orders,id',
            'origin_requisition_id' => 'nullable|integer|exists:requisitions,id',
            'origin_invoice_id' => 'nullable|integer|exists:invoices,id',
            'origin_fiscal_document_id' => 'nullable|integer|exists:fiscal_documents,id',
            'product_id' => 'nullable|integer|exists:products,id',
            'equipment_id' => 'nullable|integer|exists:equipments,id',
            'quantity' => 'required|numeric|min:0.0001',
            'serial_number' => 'nullable|string|max:255',
            'lot_number' => 'nullable|string|max:255',
            'expires_at' => 'nullable|date',
            'coverage_type' => ['required', Rule::enum(CoverageType::class)],
            'responsibility' => ['required', Rule::enum(Responsibility::class)],
            'customer_issue_description' => 'required|string',
            'technical_diagnosis' => 'nullable|string',
            'resolution_notes' => 'nullable|string',
            'supplier_protocol' => 'nullable|string|max:255',
            'advanced_replacement' => 'nullable|boolean',
            'supplier_decision' => ['required', Rule::enum(SupplierDecision::class)],
            'supplier_resolution' => ['required', Rule::enum(SupplierResolution::class)],
            'sent_to_supplier_at' => 'nullable|date',
            'returned_from_supplier_at' => 'nullable|date|after_or_equal:sent_to_supplier_at',
            'closed_at' => 'nullable|date',
        ], self::messages());

        $validator->after(function ($validator) use ($data, $resolvedCompanyId): void {
            $type = isset($data['type']) ? Type::tryFrom((string) $data['type']) : null;

            if ($type === Type::SERVICE_COMPANY) {
                if (blank($data['origin_service_order_id'] ?? null)) {
                    $validator->errors()->add('origin_service_order_id', 'A garantia de serviço exige uma ordem de serviço de origem.');
                }

                if (filled($data['supplier_id'] ?? null)) {
                    $validator->errors()->add('supplier_id', 'Garantias de serviço não devem ter fornecedor vinculado.');
                }
            }

            if ($type === Type::PRODUCT_SUPPLIER) {
                if (blank($data['product_id'] ?? null)) {
                    $validator->errors()->add('product_id', 'A garantia de peça exige um produto.');
                }

                if (blank($data['supplier_id'] ?? null)) {
                    $validator->errors()->add('supplier_id', 'A garantia de peça exige um fornecedor.');
                }

                if (blank($data['origin_requisition_id'] ?? null)
                    && blank($data['origin_invoice_id'] ?? null)
                    && blank($data['origin_fiscal_document_id'] ?? null)) {
                    $validator->errors()->add('origin_requisition_id', 'Informe ao menos uma origem comercial ou fiscal da venda.');
                }
            }

            self::validateOwnershipAndConsistency($validator, $data, $resolvedCompanyId);
        });

        return $validator;
    }

    private static function validateOwnershipAndConsistency($validator, array $data, ?int $companyId): void
    {
        if (! $companyId) {
            return;
        }

        $customerId = $data['customer_id'] ?? null;

        if (filled($data['product_id'] ?? null)) {
            $product = Product::query()->find($data['product_id']);

            if ($product?->company_id !== $companyId) {
                $validator->errors()->add('product_id', 'O produto informado não pertence à empresa atual.');
            }
        }

        if (filled($data['service_order_id'] ?? null)) {
            $serviceOrder = ServiceOrder::query()->find($data['service_order_id']);

            if ($serviceOrder?->company_id !== $companyId) {
                $validator->errors()->add('service_order_id', 'A ordem de serviço vinculada não pertence à empresa atual.');
            }
        }

        if (filled($data['origin_service_order_id'] ?? null)) {
            $originServiceOrder = ServiceOrder::query()->find($data['origin_service_order_id']);

            if ($originServiceOrder?->company_id !== $companyId) {
                $validator->errors()->add('origin_service_order_id', 'A ordem de serviço de origem não pertence à empresa atual.');
            }

            if ($originServiceOrder && $customerId && (int) $originServiceOrder->customer_id !== (int) $customerId) {
                $validator->errors()->add('customer_id', 'O cliente deve ser o mesmo da ordem de serviço de origem.');
            }
        }

        if (filled($data['origin_requisition_id'] ?? null)) {
            $requisition = Requisition::query()->with('items')->find($data['origin_requisition_id']);

            if ($requisition?->company_id !== $companyId) {
                $validator->errors()->add('origin_requisition_id', 'A requisição de origem não pertence à empresa atual.');
            }

            if ($requisition && $customerId && (int) $requisition->customer_id !== (int) $customerId) {
                $validator->errors()->add('customer_id', 'O cliente deve ser o mesmo da requisição de origem.');
            }

            if ($requisition && filled($data['product_id'] ?? null)
                && ! $requisition->items->contains(fn ($item): bool => (int) $item->product_id === (int) $data['product_id'])) {
                $validator->errors()->add('product_id', 'O produto informado não existe na requisição de origem.');
            }
        }

        if (filled($data['origin_invoice_id'] ?? null)) {
            $invoice = Invoice::query()->find($data['origin_invoice_id']);

            if ($invoice?->company_id !== $companyId) {
                $validator->errors()->add('origin_invoice_id', 'A fatura de origem não pertence à empresa atual.');
            }

            if ($invoice && $customerId && (int) $invoice->customer_id !== (int) $customerId) {
                $validator->errors()->add('customer_id', 'O cliente deve ser o mesmo da fatura de origem.');
            }
        }

        if (filled($data['origin_fiscal_document_id'] ?? null)) {
            $fiscalDocument = FiscalDocument::query()->find($data['origin_fiscal_document_id']);

            if ($fiscalDocument?->company_id !== $companyId) {
                $validator->errors()->add('origin_fiscal_document_id', 'O documento fiscal de origem não pertence à empresa atual.');
            }

            if ($fiscalDocument && $customerId && (int) $fiscalDocument->customer_id !== (int) $customerId) {
                $validator->errors()->add('customer_id', 'O cliente deve ser o mesmo do documento fiscal de origem.');
            }
        }
    }

    private static function messages(): array
    {
        return [
            'number.required' => 'O número da garantia é obrigatório.',
            'number.unique' => 'Já existe uma garantia com esse número para a empresa.',
            'company_id.required' => 'A empresa é obrigatória.',
            'type.required' => 'O tipo da garantia é obrigatório.',
            'customer_id.required' => 'O cliente é obrigatório.',
            'quantity.required' => 'A quantidade é obrigatória.',
            'quantity.min' => 'A quantidade deve ser maior que zero.',
            'coverage_type.required' => 'A cobertura é obrigatória.',
            'responsibility.required' => 'A responsabilidade é obrigatória.',
            'customer_issue_description.required' => 'Descreva o problema informado pelo cliente.',
        ];
    }
}
