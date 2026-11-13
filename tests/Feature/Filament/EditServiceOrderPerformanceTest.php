<?php

namespace Tests\Feature\Filament;

use App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\EditServiceOrder;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\ServiceOrderResource;
use App\Models\Company;
use App\Models\ServiceOrder;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class EditServiceOrderPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private ServiceOrder $order;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $company = Company::factory()->create(['created_by' => $user->id]);
        $user->companies()->attach($company, ['role' => 'admin', 'is_active' => true]);
        $this->actingAs($user);
        Filament::setCurrentPanel('admin');
        Filament::setTenant($company);
        ServiceOrderResource::registerTenancyModelGlobalScope(Filament::getCurrentPanel());
        $this->order = ServiceOrder::factory()->create([
            'company_id' => $company->id,
            'created_by' => $user->id,
        ]);
    }

    public function test_initial_render_defers_tables_and_only_looks_up_return_document_once(): void
    {
        DB::enableQueryLog();

        try {
            Livewire::test(EditServiceOrder::class, ['record' => $this->order->id])
                ->assertSuccessful()
                ->assertFormSet(['customer_id' => $this->order->customer_id])
                ->assertActionHidden('view-linked-return-fiscal-document');

            $queries = collect(DB::getQueryLog())->pluck('query');
            $returnQueries = $queries->filter(fn (string $sql): bool => str_contains($sql, 'fiscal_document_item_origins'));
            $this->assertCount(1, $returnQueries, $returnQueries->implode("\n"));
            $this->assertEmpty($queries->filter(fn (string $sql): bool => str_contains($sql, 'from "attachments"')));
            // Aggregate totals are required by the form, but table pagination is deferred.
            $this->assertEmpty($queries->filter(fn (string $sql): bool => str_contains($sql, 'from "service_order_items"') && str_contains($sql, 'limit') && ! str_contains(strtolower($sql), 'sum(')));
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }

    public function test_edit_page_cannot_load_an_order_from_another_tenant(): void
    {
        $otherOrder = ServiceOrder::factory()->create();

        $this->expectException(ModelNotFoundException::class);

        Livewire::test(EditServiceOrder::class, ['record' => $otherOrder->id]);
    }
}
