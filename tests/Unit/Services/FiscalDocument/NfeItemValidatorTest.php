<?php

namespace Tests\Unit\Services\FiscalDocument;

use App\Enum\Product\OriginSalePrice;
use App\Enum\Product\Unit;
use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use App\Services\FiscalDocument\Validators\Items\NfeItemValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class NfeItemValidatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_validate_update_accepts_product_code_when_present(): void
    {
        $validated = NfeItemValidator::validateUpdate([
            'product_code' => 'PRD-001',
        ]);

        $this->assertSame('PRD-001', $validated['product_code']);
    }

    public function test_validate_update_rejects_null_product_code(): void
    {
        $this->expectException(ValidationException::class);

        NfeItemValidator::validateUpdate([
            'product_code' => null,
        ]);
    }

    public function test_validate_accepts_complete_ibs_cbs_block(): void
    {
        $product = $this->makeProduct();

        $validated = NfeItemValidator::validate([
            'items' => [$this->validEmissionItem($product, [
                'ibs_cbs' => [
                    'situacao_tributaria' => '000',
                    'classificacao_tributaria' => '000001',
                    'grupo_ibs_cbs' => [
                        'valor_base_calculo' => '100.00',
                        'valor_total_ibs' => '0.10',
                        'ibs_estadual' => [
                            'aliquota' => '0.0500',
                            'valor' => '0.05',
                        ],
                        'ibs_municipal' => [
                            'aliquota' => '0.0500',
                            'valor' => '0.05',
                        ],
                        'cbs' => [
                            'aliquota' => '0.9000',
                            'valor' => '0.90',
                        ],
                    ],
                ],
            ])],
        ]);

        $this->assertSame($product->id, data_get($validated, 'items.0.product_id'));
    }

    public function test_validate_rejects_incomplete_ibs_cbs_block(): void
    {
        $product = $this->makeProduct();

        $this->expectException(ValidationException::class);

        NfeItemValidator::validate([
            'items' => [$this->validEmissionItem($product, [
                'ibs_cbs' => [
                    'situacao_tributaria' => '000',
                ],
            ])],
        ]);
    }

    private function validEmissionItem(Product $product, array $taxOverrides = []): array
    {
        return [
            'product_id' => $product->id,
            'product_code' => $product->product_code,
            'description' => $product->name,
            'ncm_code' => '84733049',
            'cfop_code' => '5102',
            'product_origin' => '0',
            'quantity' => 1,
            'unit_price' => 100,
            'total_price' => 100,
            'unit_of_measure' => 'UN',
            'tax_data' => [
                'imposto' => array_replace_recursive([
                    'icms' => ['situacao_tributaria' => '00'],
                    'pis' => ['situacao_tributaria' => '01'],
                    'cofins' => ['situacao_tributaria' => '01'],
                ], $taxOverrides),
            ],
        ];
    }

    private function makeProduct(): Product
    {
        $user = User::factory()->create();
        $company = Company::query()->create([
            'name' => 'Empresa Validator',
            'document_number' => '12345678000199',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'created_by' => $user->id,
        ]);

        return Product::query()->create([
            'company_id' => $company->id,
            'product_code' => 'PRD-VAL-001',
            'name' => 'Produto Validator',
            'unit' => Unit::UN->value,
            'origin_sale_price' => OriginSalePrice::FREE->value,
            'sale_price_value' => 100,
            'is_active' => true,
            'created_by' => $user->id,
        ]);
    }
}
