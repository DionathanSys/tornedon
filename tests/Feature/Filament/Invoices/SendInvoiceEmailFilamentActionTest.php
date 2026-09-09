<?php

namespace Tests\Feature\Filament\Invoices;

use App\Enum\FiscalDocument\DocumentModel;
use App\Enum\FiscalDocument\Status as FiscalDocumentStatus;
use App\Enum\Invoice\Status as InvoiceStatus;
use App\Filament\Clusters\Financial\Resources\Invoices\Pages\EditInvoice;
use App\Models\Company;
use App\Models\CompanyPartner;
use App\Models\Contact;
use App\Models\FiscalDocument;
use App\Models\Invoice;
use App\Models\Partner;
use App\Models\User;
use App\Services\Invoice\Actions\SendInvoiceEmailAction as SendInvoiceEmailServiceAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class SendInvoiceEmailFilamentActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_edit_invoice_action_opens_modal_and_dispatches_service_with_edited_data(): void
    {
        $user = User::factory()->create();

        $company = Company::query()->create([
            'name' => sprintf('Empresa Filament %s', str()->uuid()),
            'document_number' => '98765432000199',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'email' => 'empresa-filament@example.com',
            'is_active' => true,
            'created_by' => $user->id,
        ]);

        $customer = Partner::query()->create([
            'name' => sprintf('Cliente Filament %s', str()->uuid()),
            'document_type' => 'CPF',
            'document_number' => fake()->unique()->numerify('###########'),
            'created_by' => $user->id,
        ]);

        $user->companies()->attach($company, [
            'role' => 'admin',
            'is_active' => true,
        ]);

        $invoice = Invoice::query()->create([
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'invoice_number' => 'INV-UI-001',
            'invoice_date' => now()->toDateString(),
            'status' => InvoiceStatus::PENDING->value,
            'pending' => true,
            'confirmed' => false,
            'canceled' => false,
        ]);

        $companyPartner = CompanyPartner::query()->create([
            'company_id' => $company->id,
            'partner_id' => $customer->id,
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

        FiscalDocument::query()->create([
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'invoice_id' => $invoice->id,
            'status' => FiscalDocumentStatus::CONFIRMED->value,
            'issued_at' => now()->toDateString(),
            'movement_at' => now()->toDateString(),
            'document_type' => DocumentModel::NFE->value,
            'document_number' => '9001',
            'nfe_payload' => ['xml' => '<xml>ok</xml>'],
            'pending' => false,
            'confirmed' => true,
            'canceled' => false,
            'confirmed_at' => now(),
        ]);

        $service = Mockery::mock(SendInvoiceEmailServiceAction::class);
        $service->shouldReceive('execute')
            ->once()
            ->withArgs(function (Invoice $record, string $subject, string $body, int $userId) use ($invoice, $user): bool {
                return $record->is($invoice)
                    && $subject === 'Assunto editado'
                    && $body === 'Corpo editado'
                    && $userId === $user->id;
            })
            ->andReturn(true);
        $service->shouldReceive('hasError')->once()->andReturn(false);
        $this->app->instance(SendInvoiceEmailServiceAction::class, $service);

        $this->actingAs($user);
        Filament::setCurrentPanel('admin');
        Filament::setTenant($company);

        Livewire::test(EditInvoice::class, ['record' => (string) $invoice->getRouteKey()])
            ->assertActionExists('sendInvoiceEmail')
            ->assertActionVisible('sendInvoiceEmail')
            ->mountAction('sendInvoiceEmail')
            ->assertMountedActionModalSee('Enviar e-mail da fatura')
            ->setActionData([
                'subject' => 'Assunto editado',
                'body' => 'Corpo editado',
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors();
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }
}
