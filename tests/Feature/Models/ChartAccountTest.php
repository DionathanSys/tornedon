<?php

namespace Tests\Feature\Models;

use App\Enum\Financial\ChartAccountType;
use App\Models\ChartAccount;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ChartAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_root_child_grandchild_and_resolves_tree_helpers(): void
    {
        $company = $this->createCompany('Empresa Arvore');

        $root = $this->createAccount($company->id, '2', 'Custos Operacionais', null, false);
        $child = $this->createAccount($company->id, '2.01', 'Custos da Frota', $root->id, false);
        $grandchild = $this->createAccount($company->id, '2.01.01', 'Combustíveis', $child->id);

        $this->assertTrue($root->fresh()->children->contains('id', $child->id));
        $this->assertSame($root->id, $grandchild->root()->id);
        $this->assertSame([$child->id, $root->id], $grandchild->ancestors()->pluck('id')->all());
        $this->assertSame([$child->id, $grandchild->id], $root->descendants()->pluck('id')->all());
        $this->assertSame('2 - Custos Operacionais / 2.01 - Custos da Frota / 2.01.01 - Combustíveis', $grandchild->full_name);
    }

    public function test_it_supports_more_than_five_levels(): void
    {
        $company = $this->createCompany('Empresa Profunda');
        $parentId = null;
        $last = null;

        for ($level = 1; $level <= 6; $level++) {
            $last = $this->createAccount($company->id, (string) $level, "Nivel {$level}", $parentId);
            $parentId = $last->id;
        }

        $this->assertNotNull($last);
        $this->assertCount(5, $last->ancestors());
    }

    public function test_it_prevents_self_parent(): void
    {
        $company = $this->createCompany('Empresa Self Parent');
        $account = $this->createAccount($company->id, '1', 'Receitas');

        $this->expectException(ValidationException::class);

        $account->update(['parent_id' => $account->id]);
    }

    public function test_it_prevents_parent_from_another_company(): void
    {
        $company = $this->createCompany('Empresa A');
        $otherCompany = $this->createCompany('Empresa B');
        $foreignParent = $this->createAccount($otherCompany->id, '1', 'Conta Externa');

        $this->expectException(ValidationException::class);

        $this->createAccount($company->id, '2', 'Conta Interna', $foreignParent->id);
    }

    public function test_it_prevents_cycles_when_moving_accounts(): void
    {
        $company = $this->createCompany('Empresa Ciclo');
        $root = $this->createAccount($company->id, '1', 'Raiz');
        $child = $this->createAccount($company->id, '1.01', 'Filho', $root->id);
        $grandchild = $this->createAccount($company->id, '1.01.01', 'Neto', $child->id);

        $this->expectException(ValidationException::class);

        $root->update(['parent_id' => $grandchild->id]);
    }

    public function test_root_account_can_be_postable_and_group_account_can_be_not_postable(): void
    {
        $company = $this->createCompany('Empresa Lancavel');

        $postableRoot = $this->createAccount($company->id, '1', 'Combustível', null, true);
        $group = $this->createAccount($company->id, '2', 'Custos', null, false);

        $this->assertTrue($postableRoot->is_postable);
        $this->assertFalse($group->is_postable);
    }

    public function test_it_soft_deletes_accounts(): void
    {
        $company = $this->createCompany('Empresa Delete');
        $account = $this->createAccount($company->id, '1', 'Receitas');

        $account->delete();

        $this->assertSoftDeleted('chart_accounts', ['id' => $account->id]);
    }

    private function createCompany(string $name): Company
    {
        $user = User::factory()->create();

        return Company::query()->create([
            'name' => $name,
            'document_number' => fake()->numerify('##############'),
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'created_by' => $user->id,
        ]);
    }

    private function createAccount(int $companyId, string $code, string $name, ?int $parentId = null, bool $isPostable = true): ChartAccount
    {
        return ChartAccount::query()->create([
            'company_id' => $companyId,
            'parent_id' => $parentId,
            'code' => $code,
            'name' => $name,
            'type' => ChartAccountType::COST->value,
            'is_postable' => $isPostable,
            'is_active' => true,
            'sort_order' => 0,
        ]);
    }
}
