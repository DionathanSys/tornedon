<?php

namespace Tests\Feature\Services\Financial;

use App\Filament\Clusters\Financial\Resources\AccountPayableInstallments\Pages\ListAccountPayableInstallments;
use App\Filament\Clusters\Financial\Resources\AccountReceivableInstallments\Pages\ListAccountReceivableInstallments;
use App\Filament\Operation\OperationModules;
use App\Models\AccountPayable;
use App\Models\AccountPayableInstallment;
use App\Models\AccountReceivable;
use App\Models\AccountReceivableInstallment;
use App\Models\Company;
use App\Models\User;
use App\Services\Access\UserAccessConfigurationService;
use App\Services\Financial\ChangeInstallmentStatusService;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ChangeInstallmentStatusServiceTest extends TestCase
{
    use RefreshDatabase;

    public static function types(): array
    {
        return [
            'pagar' => [AccountPayableInstallment::class, 'paid', ListAccountPayableInstallments::class],
            'receber' => [AccountReceivableInstallment::class, 'received', ListAccountReceivableInstallments::class],
        ];
    }

    #[DataProvider('types')]
    public function test_bulk_action_changes_only_status_without_payments_or_movements(string $model, string $status, string $page): void
    {
        [$user, $company] = $this->authenticate();
        $this->grant($user, $company, $model);
        $records = new Collection([$this->installment($model, $company), $this->installment($model, $company)]);
        $before = $records->map(fn ($record) => $record->fresh()->getRawOriginal());

        // Legacy service remains compatible; the UI now uses explicit consideration instead.
        app(ChangeInstallmentStatusService::class)->change($records, $model, $status, $user, $company, 'admin');

        foreach ($records as $index => $record) {
            $after = $record->fresh()->getRawOriginal();
            $this->assertSame($status, $after['status']);
            unset($after['status'], $after['updated_at']);
            $original = $before[$index];
            unset($original['status'], $original['updated_at']);
            $this->assertSame($original, $after);
        }
        $this->assertDatabaseCount('cash_movements', 0);
        $this->assertDatabaseCount('account_payable_installment_payments', 0);
        $this->assertDatabaseCount('account_receivable_installment_payments', 0);
    }

    #[DataProvider('types')]
    public function test_explicit_permission_is_required_even_without_access_settings(string $model, string $status, string $page): void
    {
        [$user, $company] = $this->authenticate();
        $record = $this->installment($model, $company);
        Livewire::test($page)->assertTableBulkActionHidden('ignore_financial');

        $this->expectException(HttpException::class);
        app(ChangeInstallmentStatusService::class)->change(new Collection([$record]), $model, $status, $user, $company, 'admin');
    }

    #[DataProvider('types')]
    public function test_cross_company_selection_is_rejected_atomically(string $model, string $status): void
    {
        [$user, $company] = $this->authenticate();
        $this->grant($user, $company, $model);
        $local = $this->installment($model, $company);
        $foreign = $this->installment($model, Company::factory()->create());
        // A manipulated in-memory tenant must not override the persisted owner.
        $foreign->company_id = $company->id;

        try {
            app(ChangeInstallmentStatusService::class)->change(new Collection([$local, $foreign]), $model, $status, $user, $company, 'admin');
            $this->fail('Expected tenant authorization failure.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertSame('pending', $local->fresh()->status->value);
        $this->assertSame('pending', $foreign->fresh()->status->value);
    }

    #[DataProvider('types')]
    public function test_pending_and_invalid_statuses_are_rejected(string $model): void
    {
        [$user, $company] = $this->authenticate();
        $this->grant($user, $company, $model);
        $record = $this->installment($model, $company);
        foreach (['pending', 'invalid'] as $status) {
            try {
                app(ChangeInstallmentStatusService::class)->change(new Collection([$record]), $model, $status, $user, $company, 'admin');
                $this->fail('Expected status validation failure.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('status', $e->errors());
            }
        }
        $this->assertSame('pending', $record->fresh()->status->value);
    }

    public function test_new_permissions_are_configurable_but_not_granted_by_default(): void
    {
        [$user] = $this->authenticate();
        $rows = app(UserAccessConfigurationService::class)->formData($user);
        foreach (['account_payables', 'account_receivables'] as $module) {
            $permission = "$module.change_status_without_movement";
            $this->assertArrayHasKey($permission, OperationModules::permissions());
            $this->assertNotContains($permission, $rows[0]['permissions']);
        }
        app(UserAccessConfigurationService::class)->validate($rows, $user->companies()->pluck('companies.id')->all());
    }

    private function authenticate(): array
    {
        $user = User::factory()->create();
        $company = Company::factory()->create();
        $user->companies()->attach($company, ['role' => 'member', 'is_active' => true]);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($company);

        return [$user, $company];
    }

    private function grant(User $user, Company $company, string $model): void
    {
        $module = ChangeInstallmentStatusService::module($model);
        $user->companyAccesses()->create([
            'company_id' => $company->id,
            'permissions' => ["$module.access", "$module.change_status_without_movement"],
            'panels' => ['admin' => true], 'navigation' => [],
        ]);
    }

    private function installment(string $model, Company $company): AccountPayableInstallment|AccountReceivableInstallment
    {
        $payable = $model === AccountPayableInstallment::class;
        $parentModel = $payable ? AccountPayable::class : AccountReceivable::class;
        $parent = $parentModel::create([
            'company_id' => $company->id, 'manual_counterparty_name' => 'Contraparte',
            ...($payable ? ['sequence_number' => '01'] : []),
            'status' => 'pending', 'due_date' => today()->addMonth(), 'due_amount' => 150, 'paid_amount' => 0,
        ]);

        return $model::create([
            $payable ? 'account_payable_id' : 'account_receivable_id' => $parent->id,
            'company_id' => $company->id, 'sequence_number' => '01', 'status' => 'pending',
            'due_date' => today()->addMonth(), 'original_amount' => 150, 'due_amount' => 150, 'balance_amount' => 150,
        ]);
    }
}
