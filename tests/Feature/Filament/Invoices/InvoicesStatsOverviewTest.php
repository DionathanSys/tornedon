<?php

namespace Tests\Feature\Filament\Invoices;

use App\Enum\Invoice\Status;
use App\Filament\Clusters\Financial\Resources\Invoices\Widgets\InvoicesStatsOverview;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Partner;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class InvoicesStatsOverviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_period_comparison_renders_and_refreshes_with_current_tenant_filters(): void
    {
        $user = User::factory()->create();
        $company = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $customer = Partner::factory()->create();
        $user->companies()->attach($company, ['role' => 'admin', 'is_active' => true]);

        foreach ([
            [$company, '2026-10-03'],
            [$otherCompany, '2026-10-03'],
        ] as $index => [$invoiceCompany, $date]) {
            Invoice::query()->create([
                'company_id' => $invoiceCompany->id,
                'customer_id' => $customer->id,
                'invoice_number' => 'INV-STATS-'.$index,
                'invoice_date' => $date,
                'status' => Status::PENDING->value,
                'pending' => true,
                'confirmed' => false,
                'canceled' => false,
            ]);
        }

        $this->actingAs($user);
        Filament::setCurrentPanel('admin');
        Filament::bootCurrentPanel();
        Filament::setTenant($company);

        Livewire::test(InvoicesStatsOverview::class, [
            'tableFilters' => [
                'invoice_date' => [
                    'invoice_date' => '30/09/2026 - 06/10/2026',
                    'isActive' => true,
                ],
            ],
        ])
            ->assertOk()
            ->assertViewHas('cards', function (array $cards): bool {
                $this->assertSame('Base comparativa', $cards[2]['footer_label']);
                $this->assertSame('1', $cards[3]['value']);

                return true;
            })
            ->call('$refresh')
            ->assertOk()
            ->assertViewHas('cards', fn (array $cards): bool => $cards[2]['footer_label'] === 'Base comparativa'
                && $cards[3]['value'] === '1'
            );
    }
}
