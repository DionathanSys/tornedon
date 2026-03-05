<?php

namespace Tests\Unit\Services\Quote;

use App\Enum\Payment\Condition;
use App\Enum\Payment\Method;
use App\Models\Company;
use App\Models\Partner;
use App\Services\Quote\Validators\QuoteValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class QuoteValidatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_validate_create_with_valid_data()
    {
        $company = Company::factory()->create();
        $customer = Partner::factory()->create();

        $data = [
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            'payment_method' => Method::PIX->value,
            'payment_condition' => Condition::CASH->value,
            'description' => 'Teste de Orçamento',
        ];

        $validated = QuoteValidator::validateCreate($data);

        $this->assertEquals($data['company_id'], $validated['company_id']);
        $this->assertEquals($data['customer_id'], $validated['customer_id']);
    }

    public function test_validate_create_fails_with_missing_required_fields()
    {
        $this->expectException(ValidationException::class);

        $data = [
            'description' => 'Incompleto',
        ];

        QuoteValidator::validateCreate($data);
    }

    public function test_validate_create_fails_with_invalid_enums()
    {
        $this->expectException(ValidationException::class);

        $company = Company::factory()->create();
        $customer = Partner::factory()->create();

        $data = [
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            'payment_method' => 'INVALID_METHOD',
            'payment_condition' => 'INVALID_CONDITION',
        ];

        QuoteValidator::validateCreate($data);
    }

    public function test_validate_update_with_valid_data()
    {
        $customer = Partner::factory()->create();

        $data = [
            'customer_id' => $customer->id,
            'payment_method' => Method::STORE_CREDIT->value,
            'payment_condition' => Condition::DAYS_30_60_90->value,
        ];  

        $validated = QuoteValidator::validateUpdate($data, 1);

        $this->assertEquals($data['customer_id'], $validated['customer_id']);
        $this->assertEquals($data['payment_method'], $validated['payment_method']);
    }
}
