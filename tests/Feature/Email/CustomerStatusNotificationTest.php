<?php

namespace Tests\Feature\Email;

use App\Enum\Invoice\Status as InvoiceStatus;
use App\Models\Company;
use App\Models\CompanyPartner;
use App\Models\CompanyPreference;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Partner;
use App\Services\Email\Contracts\EmailProviderInterface;
use App\Services\Email\DTO\EmailMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class CustomerStatusNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_sends_email_when_invoice_status_matches_company_configuration(): void
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

        CompanyPreference::set(CompanyPreference::CUSTOMER_STATUS_NOTIFICATION_CONFIG_KEY, [
            'service_order' => ['enabled' => false, 'statuses' => []],
            'requisition' => ['enabled' => false, 'statuses' => []],
            'invoice' => ['enabled' => true, 'statuses' => [InvoiceStatus::CONFIRMED->value]],
            'fiscal_document' => ['enabled' => false, 'statuses' => []],
        ], $company->id);

        CompanyPreference::set(CompanyPreference::CUSTOMER_STATUS_NOTIFICATION_TEMPLATES_KEY, [
            'invoice' => [
                'subject' => 'Fatura {{document_number}} mudou para {{new_status}}',
                'body' => 'Olá {{partner_name}}, status {{old_status}} -> {{new_status}}',
            ],
        ], $company->id);

        $mock = Mockery::mock(EmailProviderInterface::class);
        $mock->shouldReceive('send')
            ->once()
            ->withArgs(function (EmailMessage $message): bool {
                return $message->to === ['cliente@example.com']
                    && str_contains($message->subject, 'mudou para confirmed')
                    && str_contains($message->html, 'status pending -> confirmed');
            });

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
    }

    public function test_does_not_send_email_when_status_is_not_configured(): void
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

        CompanyPreference::set(CompanyPreference::CUSTOMER_STATUS_NOTIFICATION_CONFIG_KEY, [
            'service_order' => ['enabled' => false, 'statuses' => []],
            'requisition' => ['enabled' => false, 'statuses' => []],
            'invoice' => ['enabled' => true, 'statuses' => [InvoiceStatus::CANCELLED->value]],
            'fiscal_document' => ['enabled' => false, 'statuses' => []],
        ], $company->id);

        $mock = Mockery::mock(EmailProviderInterface::class);
        $mock->shouldReceive('send')->never();
        $this->app->instance(EmailProviderInterface::class, $mock);

        $invoice = Invoice::query()->create([
            'customer_id' => $partner->id,
            'company_id' => $company->id,
            'invoice_number' => 'INV-002',
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
