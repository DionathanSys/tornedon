<?php

namespace Tests\Feature\Services\Payment;

use App\Enum\Partner\Type as PartnerType;
use App\Enum\Payment\Condition;
use App\Enum\Payment\Method;
use App\Models\Company;
use App\Models\CompanyPartner;
use App\Models\CompanyPreference;
use App\Models\Partner;
use App\Models\User;
use App\Services\Payment\CustomerPaymentDefaultsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerPaymentDefaultsResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_prefers_explicit_document_values_over_customer_and_company_defaults(): void
    {
        [$company, $customer] = $this->makeContext();

        CompanyPreference::setDefaultPaymentMethod(Method::PIX->value, $company->id);
        CompanyPreference::setDefaultPaymentCondition(Condition::CASH->value, $company->id);

        CompanyPartner::query()->create([
            'company_id' => $company->id,
            'partner_id' => $customer->id,
            'type' => [PartnerType::CUSTOMER->value],
            'invoice_threshold' => 0,
            'customer_discount_percentage' => 0,
            'payment_method' => Method::BANK_SLIP->value,
            'payment_condition' => Condition::DAYS_30->value,
            'is_active' => true,
        ]);

        $resolved = app(CustomerPaymentDefaultsResolver::class)->resolve(
            $company->id,
            $customer->id,
            Method::CREDIT_CARD,
            Condition::INSTALLMENTS_3X,
        );

        $this->assertSame(Method::CREDIT_CARD->value, $resolved['payment_method']);
        $this->assertSame(Condition::INSTALLMENTS_3X->value, $resolved['payment_condition']);
    }

    public function test_uses_customer_defaults_when_document_values_are_missing(): void
    {
        [$company, $customer] = $this->makeContext();

        CompanyPreference::setDefaultPaymentMethod(Method::PIX->value, $company->id);
        CompanyPreference::setDefaultPaymentCondition(Condition::CASH->value, $company->id);

        CompanyPartner::query()->create([
            'company_id' => $company->id,
            'partner_id' => $customer->id,
            'type' => [PartnerType::CUSTOMER->value],
            'invoice_threshold' => 0,
            'customer_discount_percentage' => 0,
            'payment_method' => Method::BANK_TRANSFER->value,
            'payment_condition' => Condition::DAYS_45->value,
            'is_active' => true,
        ]);

        $resolved = app(CustomerPaymentDefaultsResolver::class)->resolve($company->id, $customer->id);

        $this->assertSame(Method::BANK_TRANSFER->value, $resolved['payment_method']);
        $this->assertSame(Condition::DAYS_45->value, $resolved['payment_condition']);
    }

    public function test_falls_back_to_company_preferences_when_customer_has_no_payment_rule(): void
    {
        [$company, $customer] = $this->makeContext();

        CompanyPreference::setDefaultPaymentMethod(Method::PIX->value, $company->id);
        CompanyPreference::setDefaultPaymentCondition(Condition::CASH->value, $company->id);

        CompanyPartner::query()->create([
            'company_id' => $company->id,
            'partner_id' => $customer->id,
            'type' => [PartnerType::CUSTOMER->value],
            'invoice_threshold' => 0,
            'customer_discount_percentage' => 0,
            'is_active' => true,
        ]);

        $resolved = app(CustomerPaymentDefaultsResolver::class)->resolve($company->id, $customer->id);

        $this->assertSame(Method::PIX->value, $resolved['payment_method']);
        $this->assertSame(Condition::CASH->value, $resolved['payment_condition']);
    }

    public function test_returns_nulls_when_no_source_has_payment_defaults(): void
    {
        [$company, $customer] = $this->makeContext();

        $resolved = app(CustomerPaymentDefaultsResolver::class)->resolve($company->id, $customer->id);

        $this->assertNull($resolved['payment_method']);
        $this->assertNull($resolved['payment_condition']);
    }

    private function makeContext(): array
    {
        $user = User::factory()->create();

        $company = Company::query()->create([
            'name' => 'Empresa Payment Resolver',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'created_by' => $user->id,
        ]);

        $customer = Partner::query()->create([
            'name' => 'Cliente Resolver',
            'document_type' => 'cpf',
            'document_number' => fake()->numerify('###########'),
            'created_by' => $user->id,
        ]);

        return [$company, $customer];
    }
}
