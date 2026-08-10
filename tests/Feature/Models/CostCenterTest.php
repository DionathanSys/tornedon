<?php

namespace Tests\Feature\Models;

use App\Models\Company;
use App\Models\CostCenter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CostCenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_hierarchy_and_full_name_options(): void
    {
        $company = $this->createCompany('Empresa Centro Custo');

        $root = $this->createCenter($company->id, '10', 'Oficina');
        $child = $this->createCenter($company->id, '10.01', 'Mecânica', $root->id);
        $grandchild = $this->createCenter($company->id, '10.01.01', 'Diesel', $child->id);

        $this->assertTrue($root->fresh()->children->contains('id', $child->id));
        $this->assertSame('10 - Oficina / 10.01 - Mecânica / 10.01.01 - Diesel', $grandchild->full_name);
        $this->assertSame($grandchild->full_name, CostCenter::optionsForCompany($company->id)[$grandchild->id]);
    }

    public function test_it_prevents_parent_from_another_company(): void
    {
        $company = $this->createCompany('Empresa A');
        $otherCompany = $this->createCompany('Empresa B');
        $foreignParent = $this->createCenter($otherCompany->id, '1', 'Externo');

        $this->expectException(ValidationException::class);

        $this->createCenter($company->id, '2', 'Interno', $foreignParent->id);
    }

    public function test_it_prevents_cycles(): void
    {
        $company = $this->createCompany('Empresa Ciclo Custo');
        $root = $this->createCenter($company->id, '1', 'Raiz');
        $child = $this->createCenter($company->id, '1.01', 'Filho', $root->id);
        $grandchild = $this->createCenter($company->id, '1.01.01', 'Neto', $child->id);

        $this->expectException(ValidationException::class);

        $root->update(['parent_id' => $grandchild->id]);
    }

    public function test_it_soft_deletes_center(): void
    {
        $company = $this->createCompany('Empresa Delete Custo');
        $center = $this->createCenter($company->id, '1', 'Administrativo');

        $center->delete();

        $this->assertSoftDeleted('cost_centers', ['id' => $center->id]);
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

    private function createCenter(int $companyId, string $code, string $name, ?int $parentId = null): CostCenter
    {
        return CostCenter::query()->create([
            'company_id' => $companyId,
            'parent_id' => $parentId,
            'code' => $code,
            'name' => $name,
            'is_active' => true,
            'sort_order' => 0,
        ]);
    }
}
