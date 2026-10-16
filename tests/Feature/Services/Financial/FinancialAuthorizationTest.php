<?php

namespace Tests\Feature\Services\Financial;

use App\Filament\Pages\DreReportPage;
use App\Models\AccountPayable;
use App\Models\AccountReceivable;
use App\Models\AccountReceivableInstallmentPayment;
use App\Models\CashMovement;
use App\Models\Company;
use App\Models\DreModel;
use App\Models\User;
use App\Services\Financial\Dre\GenerateDreReportService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Mockery;
use Tests\TestCase;

class FinancialAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_financial_module_permissions_are_enforced_in_both_panels_and_on_child_records(): void
    {
        $user = User::factory()->create();
        $company = Company::factory()->create();
        $user->companies()->attach($company, ['role' => 'member', 'is_active' => true]);
        $user->companyAccesses()->create([
            'company_id' => $company->id, 'permissions' => ['cash_movements.access'],
            'panels' => ['admin' => true, 'operation' => true],
            'navigation' => [],
        ]);
        $this->actingAs($user);
        foreach (['admin', 'operation'] as $panel) {
            Filament::setCurrentPanel(Filament::getPanel($panel));
            Filament::setTenant($company);
            $this->assertFalse(Gate::forUser($user)->allows('viewAny', AccountReceivable::class));
            $this->assertFalse(Gate::forUser($user)->allows('viewAny', AccountPayable::class));
            $this->assertFalse(Gate::forUser($user)->allows('viewAny', AccountReceivableInstallmentPayment::class));
            $this->assertTrue(Gate::forUser($user)->allows('viewAny', CashMovement::class));
            $record = new CashMovement;
            $record->company_id = $company->id + 1;
            $this->assertFalse(Gate::forUser($user)->allows('update', $record));
        }
    }

    public function test_dre_rejects_manipulated_company_ids_and_inactive_memberships_before_generating_report(): void
    {
        $user = User::factory()->create();
        $company = Company::factory()->create();
        $other = Company::factory()->create();
        $inactive = Company::factory()->create();
        $user->companies()->attach($company, ['role' => 'member', 'is_active' => true]);
        $user->companies()->attach($inactive, ['role' => 'member', 'is_active' => false]);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($company);
        $model = DreModel::create(['company_id' => $company->id, 'name' => 'DRE', 'is_active' => true]);
        $service = Mockery::mock(GenerateDreReportService::class);
        $service->shouldNotReceive('generate');
        app()->instance(GenerateDreReportService::class, $service);

        foreach ([[$other->id], [$inactive->id], []] as $companyIds) {
            $page = new DreReportPage;
            $page->data = [
                'dre_model_id' => $model->id, 'company_ids' => $companyIds,
                'date_range' => '01/10/2026 - 31/10/2026', 'mode' => 'cash', 'view' => 'realized',
            ];
            $this->assertTrue($page->getRowsProperty()->isEmpty());
        }
    }
}
