<?php

namespace Tests\Feature\Filament;

use App\Enum\Requisition\Status;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\ListServiceOrders;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\ServiceOrderResource;
use App\Models\Company;
use App\Models\Partner;
use App\Models\Product;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderItem;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class ServiceOrdersTableTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    private Partner $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->company = Company::factory()->create(['created_by' => $this->user->id]);
        $this->customer = Partner::factory()->create();
        $this->user->companies()->attach($this->company, ['role' => 'admin', 'is_active' => true]);
        $this->actingAs($this->user);
        Filament::setCurrentPanel('admin');
        Filament::setTenant($this->company);
        ServiceOrderResource::registerTenancyModelGlobalScope(Filament::getCurrentPanel());
    }

    public function test_totals_and_delete_visibility_do_not_query_each_order_separately(): void
    {
        $service = Service::factory()->create(['company_id' => $this->company->id]);
        $product = Product::factory()->create(['company_id' => $this->company->id]);
        $orders = collect();

        for ($i = 0; $i < 5; $i++) {
            $order = $this->createOrder(['travel_value' => 12.50]);
            ServiceOrderItem::withoutEvents(fn () => ServiceOrderItem::query()->create([
                'service_order_id' => $order->id,
                'service_id' => $service->id,
                'quantity' => 2,
                'unit_price' => 100,
                'discount_amount' => 10,
            ]));
            $requisition = Requisition::withoutEvents(fn () => Requisition::query()->create([
                'number' => 'REQ-'.$i,
                'company_id' => $this->company->id,
                'customer_id' => $this->customer->id,
                'service_order_id' => $order->id,
                'sale_date' => now()->toDateString(),
                'status' => Status::OPEN,
            ]));
            RequisitionItem::withoutEvents(fn () => RequisitionItem::query()->create([
                'requisition_id' => $requisition->id,
                'product_id' => $product->id,
                'unit_of_measure' => 'UN',
                'quantity' => 2,
                'unit_price' => 50,
                'discount_amount' => 5,
            ]));
            $orders->push($order);
        }

        $withoutRequisition = $this->createOrder();
        DB::enableQueryLog();

        try {
            $page = Livewire::test(ListServiceOrders::class)
                ->assertCanSeeTableRecords($orders);

            foreach ($page->instance()->getTableRecords() as $record) {
                $this->assertSame($record->id === $withoutRequisition->id ? 0.0 : 202.5, $record->services_total_amount);
                $this->assertSame($record->id === $withoutRequisition->id ? 0.0 : 95.0, $record->requisition_total_amount);
                $this->assertSame($record->id === $withoutRequisition->id ? 0.0 : 297.5, $record->grand_total_amount);
            }

            $itemQueries = collect(DB::getQueryLog())->filter(fn (array $query): bool => preg_match('/from "(service_order_items|requisition_items|requisitions)"/', $query['query']) === 1
            );
            $this->assertCount(3, $itemQueries, $itemQueries->pluck('query')->implode("\n"));
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $page->assertTableActionHidden('delete', $orders->first())
            ->assertTableActionVisible('delete', $withoutRequisition);
    }

    public function test_date_filter_includes_boundaries_and_preserves_tenant_scope(): void
    {
        $before = $this->createOrder(['order_date' => '2026-10-01']);
        $start = $this->createOrder(['order_date' => '2026-10-02']);
        $end = $this->createOrder(['order_date' => '2026-10-03']);
        $after = $this->createOrder(['order_date' => '2026-10-04']);
        $otherTenant = $this->createOrder([
            'company_id' => Company::factory()->create()->id,
            'order_date' => '2026-10-02',
        ]);

        Livewire::test(ListServiceOrders::class)
            ->filterTable('order_date', ['from' => '2026-10-02', 'until' => '2026-10-03'])
            ->assertCanSeeTableRecords([$start, $end])
            ->assertCanNotSeeTableRecords([$before, $after, $otherTenant])
            ->filterTable('order_date', ['from' => '2026-10-03', 'until' => null])
            ->assertCanSeeTableRecords([$end, $after])
            ->assertCanNotSeeTableRecords([$before, $start, $otherTenant])
            ->filterTable('order_date', ['from' => null, 'until' => '2026-10-02'])
            ->assertCanSeeTableRecords([$before, $start])
            ->assertCanNotSeeTableRecords([$end, $after, $otherTenant]);
    }

    private function createOrder(array $attributes = []): ServiceOrder
    {
        return ServiceOrder::factory()->create(array_merge([
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'created_by' => $this->user->id,
        ], $attributes));
    }
}
