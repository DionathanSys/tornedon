<?php

namespace Tests\Unit\Services\Service;

use App\Models\Company;
use App\Services\Service\Validators\ServiceValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ServiceValidatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_accepts_null_min_sale_price(): void
    {
        $company = Company::factory()->create();

        $validated = ServiceValidator::validateCreate([
            'service_code' => 'SRV-TEST-01',
            'name' => 'Servico teste',
            'price' => 100,
            'min_sale_price' => null,
            'company_id' => $company->id,
            'accept_customer_discount' => true,
        ]);

        $this->assertNull($validated['min_sale_price']);
        $this->assertTrue($validated['accept_customer_discount']);
    }

    public function test_rejects_min_sale_price_greater_than_price(): void
    {
        $this->expectException(ValidationException::class);

        $company = Company::factory()->create();

        ServiceValidator::validateCreate([
            'service_code' => 'SRV-TEST-02',
            'name' => 'Servico invalido',
            'price' => 100,
            'min_sale_price' => 120,
            'company_id' => $company->id,
        ]);
    }
}
