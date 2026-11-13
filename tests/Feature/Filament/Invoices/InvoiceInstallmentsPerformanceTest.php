<?php

namespace Tests\Feature\Filament\Invoices;

use App\Enum\AccountReceivable\Status;
use App\Filament\Clusters\Financial\Resources\Invoices\Pages\EditInvoice;
use App\Filament\Clusters\Financial\Resources\Invoices\RelationManagers\InstallmentsRelationManager;
use App\Models\AccountReceivable;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Partner;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class InvoiceInstallmentsPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_render_reuses_account_authorization_for_all_its_installments(): void
    {
        $user = User::factory()->create();
        $company = Company::factory()->create();
        $user->companies()->attach($company, ['role' => 'owner', 'is_active' => true]);
        $this->actingAs($user);
        Filament::setCurrentPanel('admin');
        Filament::setTenant($company);
        Livewire::withoutLazyLoading();

        $invoice = Invoice::create([
            'company_id' => $company->id,
            'customer_id' => Partner::factory()->create()->id,
            'invoice_number' => 'PERF-001',
            'invoice_date' => today(),
            'status' => 'pending',
            'created_by' => $user->id,
        ]);
        $account = AccountReceivable::create([
            'company_id' => $company->id,
            'customer_id' => $invoice->customer_id,
            'invoice_id' => $invoice->id,
            'status' => Status::PENDING,
            'due_date' => today(),
            'due_amount' => 500,
            'paid_amount' => 0,
            'payment_method' => 'pix',
        ]);
        $installments = collect();
        for ($i = 1; $i <= 5; $i++) {
            $installments->push($account->installments()->create([
                'company_id' => $company->id,
                'sequence_number' => (string) $i,
                'status' => Status::PENDING,
                'due_date' => today()->addMonths($i),
                'original_amount' => 100,
                'due_amount' => 100,
                'balance_amount' => 100,
            ]));
        }

        DB::enableQueryLog();
        try {
            $page = Livewire::test(InstallmentsRelationManager::class, [
                'ownerRecord' => $invoice,
                'pageClass' => EditInvoice::class,
            ])->assertCanSeeTableRecords($installments);

            $queries = collect(DB::getQueryLog())->pluck('query');
            $paymentChecks = $queries->filter(fn (string $sql): bool => str_contains($sql, 'account_receivable_installment_payments'));
            $this->assertCount(1, $paymentChecks, $paymentChecks->implode("\n"));
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $page->assertTableActionVisible('openAccountReceivable', $installments->first());

        $installments->first()->payments()->create([
            'company_id' => $company->id,
            'payment_date' => today(),
            'amount' => 10,
        ]);

        // Authorization must be evaluated again after a receipt is recorded.
        $page->dispatch('refresh-installments')
            ->assertCanSeeTableRecords($installments)
            ->assertTableActionHidden('openAccountReceivable', $installments->first())
            ->assertTableActionHidden('openAccountReceivable', $installments->last());

        $otherAccount = AccountReceivable::create([
            'company_id' => $company->id,
            'customer_id' => $invoice->customer_id,
            'invoice_id' => $invoice->id,
            'status' => Status::PENDING,
            'due_date' => today(),
            'due_amount' => 100,
            'paid_amount' => 0,
        ]);
        $otherInstallment = $otherAccount->installments()->create([
            'company_id' => $company->id,
            'sequence_number' => '1',
            'status' => Status::PENDING,
            'due_date' => today(),
            'original_amount' => 100,
            'due_amount' => 100,
            'balance_amount' => 100,
        ]);

        $page->dispatch('refresh-installments')
            ->assertTableActionHidden('openAccountReceivable', $installments->first())
            ->assertTableActionVisible('openAccountReceivable', $otherInstallment);
    }
}
