<?php

namespace Tests\Feature\Services\ServiceOrder;

use App\Enum\ServiceOrder\Priority;
use App\Enum\ServiceOrder\State;
use App\Enum\ServiceOrder\Type;
use App\Models\Company;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use App\Models\Partner;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Services\ServiceOrder\Support\ServiceOrderPdfDataFormatter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceOrderBatchPdfViewTest extends TestCase
{
    use RefreshDatabase;

    public function test_batch_pdf_view_renders_page_break_between_service_orders(): void
    {
        $user = User::factory()->create();
        $company = Company::query()->create([
            'name' => 'Empresa Lote',
            'document_number' => '11111111000111',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'created_by' => $user->id,
        ]);
        $customer = Partner::query()->create([
            'name' => 'Cliente Lote',
            'document_type' => 'CPF',
            'document_number' => '12345678901',
            'created_by' => $user->id,
        ]);

        $serviceOrders = (new EloquentCollection([
            ServiceOrder::query()->create([
                'number' => 'OS-BATCH-001',
                'customer_id' => $customer->id,
                'company_id' => $company->id,
                'order_date' => now()->toDateString(),
                'status' => State::OPEN,
                'priority' => Priority::NORMAL,
                'type' => Type::MAINTENANCE,
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]),
            ServiceOrder::query()->create([
                'number' => 'OS-BATCH-002',
                'customer_id' => $customer->id,
                'company_id' => $company->id,
                'order_date' => now()->toDateString(),
                'status' => State::CLOSED,
                'priority' => Priority::NORMAL,
                'type' => Type::MAINTENANCE,
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]),
        ]))->load([
            'customer',
            'company',
            'items.service',
            'requisition.items.product',
        ]);

        $formatter = app(ServiceOrderPdfDataFormatter::class);

        $documents = $serviceOrders
            ->map(fn (ServiceOrder $serviceOrder): array => [
                'record' => $serviceOrder,
                'pdfData' => $formatter->format($serviceOrder),
            ])
            ->all();

        $html = view('pdf.service-order-batch', ['documents' => $documents])->render();

        $this->assertStringContainsString('page-break-after: always;', $html);
        $this->assertSame(2, substr_count($html, 'class="service-order-page"'));
        $this->assertStringContainsString('OS-BATCH-001', $html);
        $this->assertStringContainsString('OS-BATCH-002', $html);
    }
}
