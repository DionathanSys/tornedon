<?php

namespace Tests\Feature\Services\ServiceOrder;

use App\Enum\ServiceOrder\Priority;
use App\Enum\ServiceOrder\State;
use App\Enum\ServiceOrder\Type;
use App\Models\Company;
use App\Models\Partner;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Services\ServiceOrder\Support\ServiceOrderPdfDataFormatter;
use App\Services\ServiceOrder\Validators\ServiceOrderValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceOrderFollowUpResponsibleTest extends TestCase
{
    use RefreshDatabase;

    public function test_validator_accepts_follow_up_responsible_name(): void
    {
        $user = User::factory()->create();
        $company = Company::query()->create([
            'name' => 'Empresa Teste',
            'document_number' => '12345678000199',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'created_by' => $user->id,
        ]);
        $customer = Partner::query()->create([
            'name' => 'Cliente Teste',
            'document_type' => 'CPF',
            'document_number' => '12345678901',
            'created_by' => $user->id,
        ]);

        $validated = ServiceOrderValidator::validateCreate([
            'number' => 'OS-FOLLOW-UP-001',
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'order_date' => now()->toDateString(),
            'status' => State::OPEN->value,
            'priority' => Priority::NORMAL->value,
            'type' => Type::MAINTENANCE->value,
            'follow_up_responsible_name' => 'Carlos Acompanhamento',
        ]);

        $this->assertSame('Carlos Acompanhamento', $validated['follow_up_responsible_name']);
    }

    public function test_pdf_formatter_includes_follow_up_responsible_name(): void
    {
        $user = User::factory()->create();
        $company = Company::query()->create([
            'name' => 'Empresa PDF',
            'document_number' => '98765432000199',
            'address' => ['city' => 'Campinas', 'state' => 'SP'],
            'created_by' => $user->id,
        ]);
        $customer = Partner::query()->create([
            'name' => 'Cliente PDF',
            'document_type' => 'CPF',
            'document_number' => '10987654321',
            'created_by' => $user->id,
        ]);

        $serviceOrder = ServiceOrder::query()->create([
            'number' => 'OS-FOLLOW-UP-002',
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'order_date' => now()->toDateString(),
            'status' => State::OPEN,
            'priority' => Priority::NORMAL,
            'type' => Type::MAINTENANCE,
            'follow_up_responsible_name' => 'Maria Supervisora',
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $serviceOrder->load(['company', 'customer', 'items', 'requisition.items']);

        $pdfData = app(ServiceOrderPdfDataFormatter::class)->format($serviceOrder);

        $this->assertSame('Maria Supervisora', $pdfData['follow_up_responsible_name']);
    }
}
