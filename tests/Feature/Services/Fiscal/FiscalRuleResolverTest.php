<?php

namespace Tests\Feature\Services\Fiscal;

use App\Domain\DTO\Fiscal\FiscalContextDTO;
use App\Enum\FiscalDocument\OperationNature;
use App\Enum\Tax\FiscalOperationType;
use App\Enum\Tax\TaxRegime;
use App\Models\Company;
use App\Models\FiscalProfile;
use App\Models\FiscalRule;
use App\Models\User;
use App\Services\Fiscal\FiscalRuleResolver;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FiscalRuleResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_prefers_rule_for_custom_manufacturing_products(): void
    {
        $user = User::factory()->create();

        $company = Company::query()->create([
            'name' => 'Empresa Fiscal',
            'document_number' => '12345678000155',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'email' => 'fiscal@example.com',
            'is_active' => true,
            'created_by' => $user->id,
        ]);

        $profile = FiscalProfile::query()->create([
            'company_id' => $company->id,
            'tax_regime' => TaxRegime::SIMPLES_NACIONAL->value,
            'is_active' => true,
            'created_by' => $user->id,
        ]);

        FiscalRule::query()->create([
            'company_id' => $company->id,
            'fiscal_profile_id' => $profile->id,
            'operation_nature' => OperationNature::VENDA_DENTRO_ESTADO->value,
            'tax_regime' => TaxRegime::SIMPLES_NACIONAL->value,
            'is_interestadual' => false,
            'cfop' => '5102',
            'priority' => 0,
            'is_active' => true,
            'created_by' => $user->id,
        ]);

        $manufacturingRule = FiscalRule::query()->create([
            'company_id' => $company->id,
            'fiscal_profile_id' => $profile->id,
            'operation_nature' => OperationNature::VENDA_DENTRO_ESTADO->value,
            'tax_regime' => TaxRegime::SIMPLES_NACIONAL->value,
            'is_interestadual' => false,
            'is_custom_manufacturing' => true,
            'cfop' => '5101',
            'priority' => 0,
            'is_active' => true,
            'created_by' => $user->id,
        ]);

        $context = new FiscalContextDTO(
            companyId: $company->id,
            documentType: 'nfe',
            operationType: FiscalOperationType::SALE,
            movementDirection: 'out',
            issuerUf: 'SP',
            recipientUf: 'SP',
            recipientTaxpayerType: null,
            recipientFinalConsumer: true,
            productId: null,
            productNcm: '94036000',
            productCest: null,
            productOrigin: null,
            operationNature: OperationNature::VENDA_DENTRO_ESTADO->value,
            issuedAt: Carbon::parse('2026-05-12'),
            isCustomManufacturing: true,
            productHasSt: false,
        );

        $resolvedRule = app(FiscalRuleResolver::class)->resolve($context, $profile);

        $this->assertNotNull($resolvedRule);
        $this->assertTrue($resolvedRule->is($manufacturingRule));
        $this->assertSame('5101', $resolvedRule->cfop);
    }
}
