<?php

namespace Tests\Feature\Filament\Mobile;

use App\Enum\Product\OriginSalePrice;
use App\Enum\Product\Unit;
use App\Enum\Requisition\Status as RequisitionStatus;
use App\Enum\ServiceOrder\Priority;
use App\Enum\ServiceOrder\State;
use App\Enum\ServiceOrder\Type;
use App\Filament\Mobile\Pages\MobileServiceOrdersDashboard;
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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class MobileServiceOrdersDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-06-02 10:00:00');

        $compiledPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tornedon-views-test-' . Str::uuid();
        File::ensureDirectoryExists($compiledPath);

        config(['view.compiled' => $compiledPath]);

        $bladeCompiler = app('blade.compiler');
        $reflection = new \ReflectionClass($bladeCompiler);
        $cachePath = $reflection->getProperty('cachePath');
        $cachePath->setAccessible(true);
        $cachePath->setValue($bladeCompiler, $compiledPath);
    }

    public function test_mobile_dashboard_page_is_accessible_for_authenticated_tenant_user(): void
    {
        [, $company] = $this->createAuthenticatedTenant();

        $response = $this->get(MobileServiceOrdersDashboard::getUrl(['tenant' => $company]));

        $response
            ->assertOk()
            ->assertSee('Dashboard de Ordens de Serviço')
            ->assertSee('Ordens encontradas');
    }

    public function test_mobile_dashboard_shows_only_todays_orders_for_current_tenant(): void
    {
        [$user, $companyA] = $this->createAuthenticatedTenant();
        $companyB = $this->createCompanyFor($user, 'Empresa B');

        $this->createServiceOrderForDashboard(
            user: $user,
            company: $companyA,
            number: 'OS-HOJE-A',
            orderDate: Carbon::today(),
            totalAmount: 120,
            requisitionAmount: 0,
            status: State::OPEN,
        );

        $this->createServiceOrderForDashboard(
            user: $user,
            company: $companyA,
            number: 'OS-ONTEM-A',
            orderDate: Carbon::yesterday(),
            totalAmount: 90,
            requisitionAmount: 0,
            status: State::CLOSED,
        );

        $this->createServiceOrderForDashboard(
            user: $user,
            company: $companyB,
            number: 'OS-HOJE-B',
            orderDate: Carbon::today(),
            totalAmount: 200,
            requisitionAmount: 0,
            status: State::OPEN,
        );

        Livewire::test(MobileServiceOrdersDashboard::class)
            ->assertSee('OS-HOJE-A')
            ->assertDontSee('OS-ONTEM-A')
            ->assertDontSee('OS-HOJE-B')
            ->assertSee('Ordens na data')
            ->assertSee('1')
            ->assertSee('Pendentes na data');
    }

    public function test_mobile_dashboard_uses_grand_total_amount_instead_of_total_amount(): void
    {
        [$user, $company] = $this->createAuthenticatedTenant();

        $this->createServiceOrderForDashboard(
            user: $user,
            company: $company,
            number: 'OS-TOTAL',
            orderDate: Carbon::today(),
            totalAmount: 200,
            requisitionAmount: 50,
            status: State::OPEN,
        );

        Livewire::test(MobileServiceOrdersDashboard::class)
            ->assertSee('OS-TOTAL')
            ->assertSee('R$ 250,00')
            ->assertDontSee('R$ 200,00');
    }

    public function test_mobile_dashboard_renders_empty_state_with_zeroed_stats(): void
    {
        $this->createAuthenticatedTenant();

        Livewire::test(MobileServiceOrdersDashboard::class)
            ->assertSee('Nenhuma ordem de servico foi encontrada para a data selecionada.')
            ->assertSee('R$ 0,00')
            ->assertSee('Ticket médio');
    }

    public function test_mobile_dashboard_allows_changing_the_selected_date(): void
    {
        [$user, $company] = $this->createAuthenticatedTenant();

        $this->createServiceOrderForDashboard(
            user: $user,
            company: $company,
            number: 'OS-01-06',
            orderDate: Carbon::parse('2026-06-01'),
            totalAmount: 80,
            requisitionAmount: 0,
            status: State::OPEN,
        );

        $this->createServiceOrderForDashboard(
            user: $user,
            company: $company,
            number: 'OS-02-06',
            orderDate: Carbon::parse('2026-06-02'),
            totalAmount: 100,
            requisitionAmount: 0,
            status: State::OPEN,
        );

        Livewire::test(MobileServiceOrdersDashboard::class)
            ->assertSee('OS-02-06')
            ->assertDontSee('OS-01-06')
            ->set('selectedDate', '2026-06-01')
            ->assertSee('OS-01-06')
            ->assertDontSee('OS-02-06')
            ->assertSee('01/06/2026');
    }

    /**
     * @return array{User,Company}
     */
    private function createAuthenticatedTenant(): array
    {
        $user = User::factory()->create();

        $company = $this->createCompanyFor($user, 'Empresa Mobile');

        $user->companies()->attach($company, [
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->actingAs($user);
        Filament::setCurrentPanel('mobile');
        Filament::setTenant($company);

        return [$user, $company];
    }

    private function createCompanyFor(User $user, string $name): Company
    {
        return Company::query()->create([
            'name' => $name . ' ' . Str::uuid(),
            'document_number' => fake()->numerify('########000199'),
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'email' => Str::slug($name) . '@example.com',
            'is_active' => true,
            'created_by' => $user->id,
        ]);
    }

    private function createServiceOrderForDashboard(
        User $user,
        Company $company,
        string $number,
        Carbon $orderDate,
        float $totalAmount,
        float $requisitionAmount,
        State $status,
    ): ServiceOrder {
        $customer = Partner::query()->create([
            'name' => 'Cliente ' . $number,
            'document_type' => 'CPF',
            'document_number' => fake()->numerify('###########'),
            'created_by' => $user->id,
        ]);

        $service = Service::query()->create([
            'company_id' => $company->id,
            'service_code' => 'SRV-' . Str::upper(Str::random(6)),
            'name' => 'Servico ' . $number,
            'price' => $totalAmount,
            'min_sale_price' => null,
            'accept_customer_discount' => false,
            'is_active' => true,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $serviceOrder = ServiceOrder::query()->create([
            'number' => $number,
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'order_date' => $orderDate->toDateString(),
            'status' => $status,
            'priority' => Priority::NORMAL,
            'type' => Type::MAINTENANCE,
            'travel_value' => 0,
            'technician_id' => $user->id,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        ServiceOrderItem::query()->create([
            'service_order_id' => $serviceOrder->id,
            'service_id' => $service->id,
            'quantity' => 1,
            'unit_price' => $totalAmount,
            'discount_amount' => 0,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        if ($requisitionAmount > 0) {
            $product = Product::query()->create([
                'company_id' => $company->id,
                'product_code' => 'PRD-' . Str::upper(Str::random(6)),
                'name' => 'Produto ' . $number,
                'is_active' => true,
                'has_st' => false,
                'has_stock_control' => false,
                'unit' => Unit::UN,
                'origin_sale_price' => OriginSalePrice::FREE,
                'sale_price_value' => $requisitionAmount,
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]);

            $requisition = Requisition::query()->create([
                'number' => 'REQ-' . Str::upper(Str::random(6)),
                'customer_id' => $customer->id,
                'company_id' => $company->id,
                'service_order_id' => $serviceOrder->id,
                'sale_date' => $orderDate->toDateString(),
                'status' => RequisitionStatus::OPEN,
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]);

            RequisitionItem::query()->create([
                'requisition_id' => $requisition->id,
                'product_id' => $product->id,
                'unit_of_measure' => Unit::UN->value,
                'quantity' => 1,
                'unit_price' => $requisitionAmount,
                'discount_amount' => 0,
                'stock_consumed' => false,
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]);
        }

        return $serviceOrder;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }
}
