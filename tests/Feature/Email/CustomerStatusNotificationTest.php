<?php

namespace Tests\Feature\Email;

use App\Enum\Invoice\Status as InvoiceStatus;
use App\Models\Company;
use App\Models\CompanyPartner;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Partner;
use App\Services\Email\Contracts\EmailProviderInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class CustomerStatusNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_does_not_send_email_for_invoice_status_changes(): void
    {
        config(['email_notifications.enabled' => true]);

        $company = Company::factory()->create();
        $partner = Partner::factory()->create();

        $companyPartner = CompanyPartner::query()->create([
            'company_id' => $company->id,
            'partner_id' => $partner->id,
            'type' => ['customer'],
            'invoice_threshold' => 0,
            'is_active' => true,
        ]);

        Contact::query()->create([
            'company_partner_id' => $companyPartner->id,
            'email' => 'cliente@example.com',
            'notify' => true,
            'is_active' => true,
        ]);

        $mock = Mockery::mock(EmailProviderInterface::class);
        $mock->shouldReceive('send')->never();
        $this->app->instance(EmailProviderInterface::class, $mock);

        $invoice = Invoice::query()->create([
            'customer_id' => $partner->id,
            'company_id' => $company->id,
            'invoice_number' => 'INV-001',
            'invoice_date' => now()->toDateString(),
            'status' => InvoiceStatus::PENDING->value,
            'pending' => true,
            'confirmed' => false,
            'canceled' => false,
        ]);

        $invoice->update([
            'status' => InvoiceStatus::CONFIRMED->value,
            'pending' => false,
            'confirmed' => true,
        ]);

        $this->assertTrue(true);
    }
}

