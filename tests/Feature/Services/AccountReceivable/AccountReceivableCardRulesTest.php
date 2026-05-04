<?php

namespace Tests\Feature\Services\AccountReceivable;

use App\Enum\Payment\Method as PaymentMethod;
use App\Enum\Invoice\Status as InvoiceStatus;
use App\Models\AccountReceivable;
use App\Models\CardPaymentProfile;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Partner;
use App\Models\User;
use App\Services\AccountReceivable\AccountReceivableService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountReceivableCardRulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_card_receivable_keeps_neutral_card_fields(): void
    {
        $user = User::factory()->create();
        $company = $this->createCompany($user);
        $customer = $this->createCustomer($user);
        $invoice = $this->createInvoice($company, $customer, $user);

        $service = app(AccountReceivableService::class);
        $receivable = $service->create([
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'invoice_id' => $invoice->id,
            'due_date' => '2026-05-20',
            'due_amount' => 500,
            'payment_method' => PaymentMethod::PIX->value,
            'installment_count' => 1,
        ], $user->id);

        $this->assertNotNull($receivable, $service->getMessage());

        /** @var AccountReceivable $fresh */
        $fresh = $receivable->fresh();
        $this->assertSame(500.0, (float) $fresh->gross_amount);
        $this->assertSame(500.0, (float) $fresh->net_amount);
        $this->assertSame(0.0, (float) $fresh->card_fee_amount);
        $this->assertNull($fresh->card_payment_profile_id);
        $this->assertNull($fresh->card_rule_snapshot);
        $this->assertNull($fresh->expected_settlement_date);
    }

    public function test_credit_card_receivable_requires_profile_and_payment_date_and_applies_snapshot(): void
    {
        $user = User::factory()->create();
        $company = $this->createCompany($user);
        $customer = $this->createCustomer($user);
        $invoice = $this->createInvoice($company, $customer, $user);

        $service = app(AccountReceivableService::class);
        $withoutProfile = $service->create([
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'invoice_id' => $invoice->id,
            'due_date' => '2026-05-20',
            'due_amount' => 1000,
            'payment_method' => PaymentMethod::CREDIT_CARD->value,
            'payment_date' => '2026-05-04',
            'installment_count' => 1,
        ], $user->id);

        $this->assertNull($withoutProfile);
        $this->assertArrayHasKey('card_payment_profile_id', $service->getErrors());

        $profile = CardPaymentProfile::create([
            'company_id' => $company->id,
            'name' => 'Visa D+30',
            'brand' => 'Visa',
            'acquirer' => 'Stone',
            'fee_percent' => 2.99,
            'fee_fixed' => 0.5,
            'settlement_days' => 30,
            'active' => true,
        ]);

        $receivable = $service->create([
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'invoice_id' => $invoice->id,
            'due_date' => '2026-05-20',
            'due_amount' => 1000,
            'payment_method' => PaymentMethod::CREDIT_CARD->value,
            'card_payment_profile_id' => $profile->id,
            'payment_date' => '2026-05-04',
            'installment_count' => 1,
        ], $user->id);

        $this->assertNotNull($receivable, $service->getMessage());

        /** @var AccountReceivable $fresh */
        $fresh = $receivable->fresh();
        $this->assertSame(1000.0, (float) $fresh->due_amount);
        $this->assertSame(1000.0, (float) $fresh->gross_amount);
        $this->assertSame(30.40, (float) $fresh->card_fee_amount);
        $this->assertSame(969.60, (float) $fresh->net_amount);
        $this->assertSame('2026-06-03', $fresh->expected_settlement_date?->toDateString());
        $this->assertSame($profile->id, (int) $fresh->card_payment_profile_id);
        $this->assertSame($profile->id, (int) data_get($fresh->card_rule_snapshot, 'profile_id'));
    }

    private function createCompany(User $user): Company
    {
        return Company::create([
            'name' => 'Empresa Cartao AR',
            'document_number' => '11222333000199',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'created_by' => $user->id,
        ]);
    }

    private function createCustomer(User $user): Partner
    {
        return Partner::create([
            'name' => 'Cliente Cartao',
            'document_type' => 'CPF',
            'document_number' => '12345678901',
            'created_by' => $user->id,
        ]);
    }

    private function createInvoice(Company $company, Partner $customer, User $user): Invoice
    {
        return Invoice::create([
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'invoice_number' => (string) random_int(100000, 999999),
            'invoice_date' => '2026-05-04',
            'status' => InvoiceStatus::CONFIRMED->value,
            'pending' => false,
            'confirmed' => true,
            'created_by' => $user->id,
        ]);
    }
}
