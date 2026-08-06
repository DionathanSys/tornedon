<?php

namespace Tests\Feature\Filament\Settings;

use App\Enum\FiscalDocument\OperationNature;
use App\Enum\Tax\TaxRegime;
use App\Filament\Clusters\Settings\Resources\FiscalRules\FiscalRuleResource;
use App\Filament\Clusters\Settings\Resources\FiscalRules\Pages\EditFiscalRule;
use App\Models\Company;
use App\Models\FiscalProfile;
use App\Models\FiscalRule;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class FiscalRuleResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $compiledPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tornedon-fiscal-rule-views-test-'.Str::uuid();
        File::ensureDirectoryExists($compiledPath);

        config(['view.compiled' => $compiledPath]);

        $bladeCompiler = app('blade.compiler');
        $reflection = new \ReflectionClass($bladeCompiler);
        $cachePath = $reflection->getProperty('cachePath');
        $cachePath->setAccessible(true);
        $cachePath->setValue($bladeCompiler, $compiledPath);
    }

    public function test_fiscal_rule_resource_query_is_scoped_to_current_tenant(): void
    {
        [$user, $companyA] = $this->createAuthenticatedTenant();
        $companyB = $this->createCompany($user, 'Empresa Regras B', '12345678000999');

        $profileA = $this->createFiscalProfile($companyA, $user, TaxRegime::SIMPLES_NACIONAL);
        $profileB = $this->createFiscalProfile($companyB, $user, TaxRegime::LUCRO_REAL);

        $tenantRule = FiscalRule::query()->create([
            'company_id' => $companyA->id,
            'fiscal_profile_id' => $profileA->id,
            'operation_nature' => OperationNature::VENDA_DENTRO_ESTADO->value,
            'tax_regime' => TaxRegime::SIMPLES_NACIONAL->value,
            'is_interestadual' => false,
            'cfop' => '5102',
            'priority' => 10,
            'is_active' => true,
            'created_by' => $user->id,
        ]);

        $otherTenantRule = FiscalRule::query()->create([
            'company_id' => $companyB->id,
            'fiscal_profile_id' => $profileB->id,
            'operation_nature' => OperationNature::VENDA_FORA_ESTADO->value,
            'tax_regime' => TaxRegime::LUCRO_REAL->value,
            'is_interestadual' => true,
            'cfop' => '6102',
            'priority' => 20,
            'is_active' => true,
            'created_by' => $user->id,
        ]);

        Filament::setCurrentPanel('admin');
        Filament::setTenant($companyA);

        $ids = FiscalRuleResource::getEloquentQuery()->pluck('id')->all();

        $this->assertContains($tenantRule->id, $ids);
        $this->assertNotContains($otherTenantRule->id, $ids);
    }

    public function test_fiscal_rule_form_preserves_nullable_boolean_criteria(): void
    {
        [$user, $company] = $this->createAuthenticatedTenant();
        $profile = $this->createFiscalProfile($company, $user, TaxRegime::SIMPLES_NACIONAL);

        $rule = FiscalRule::query()->create([
            'company_id' => $company->id,
            'fiscal_profile_id' => $profile->id,
            'operation_nature' => OperationNature::VENDA_DENTRO_ESTADO->value,
            'tax_regime' => TaxRegime::SIMPLES_NACIONAL->value,
            'is_interestadual' => false,
            'is_custom_manufacturing' => false,
            'has_st' => false,
            'is_final_consumer' => null,
            'cfop' => '5102',
            'priority' => 10,
            'is_active' => true,
            'created_by' => $user->id,
        ]);

        Livewire::test(EditFiscalRule::class, ['record' => (string) $rule->getRouteKey()])
            ->assertSet('data.is_custom_manufacturing', '0')
            ->assertSet('data.has_st', '0')
            ->assertSet('data.is_final_consumer', null)
            ->set('data.is_custom_manufacturing', '0')
            ->set('data.has_st', '1')
            ->set('data.is_final_consumer', null)
            ->call('save')
            ->assertHasNoErrors();

        $rule->refresh();

        $this->assertFalse($rule->is_custom_manufacturing);
        $this->assertTrue($rule->has_st);
        $this->assertNull($rule->is_final_consumer);
    }

    /**
     * @return array{User,Company}
     */
    private function createAuthenticatedTenant(): array
    {
        $user = User::factory()->create([
            'email' => 'fiscal-rule-admin@example.com',
            'is_admin' => true,
        ]);

        $company = $this->createCompany($user, 'Empresa Regras A', '12345678000111');

        $user->companies()->attach($company, [
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->actingAs($user);
        Filament::setCurrentPanel('admin');
        Filament::setTenant($company);

        return [$user, $company];
    }

    private function createCompany(User $user, string $name, string $documentNumber): Company
    {
        $suffix = substr($documentNumber, -4);

        return Company::query()->create([
            'name' => "{$name} {$suffix}",
            'document_number' => $documentNumber,
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'email' => Str::slug("{$name}-{$suffix}").'@example.com',
            'is_active' => true,
            'created_by' => $user->id,
        ]);
    }

    private function createFiscalProfile(Company $company, User $user, TaxRegime $regime): FiscalProfile
    {
        return FiscalProfile::query()->create([
            'company_id' => $company->id,
            'tax_regime' => $regime->value,
            'is_active' => true,
            'created_by' => $user->id,
        ]);
    }
}
