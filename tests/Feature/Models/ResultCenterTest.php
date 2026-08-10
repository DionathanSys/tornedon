<?php

namespace Tests\Feature\Models;

use App\Models\Company;
use App\Models\ResultCenter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ResultCenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_hierarchy_and_full_name_options(): void
    {
        $company = $this->createCompany('Empresa Centro Resultado');

        $root = $this->createCenter($company->id, '20', 'Operações');
        $child = $this->createCenter($company->id, '20.01', 'Transporte', $root->id);
        $grandchild = $this->createCenter($company->id, '20.01.01', 'Frota Própria', $child->id);

        $this->assertTrue($root->fresh()->children->contains('id', $child->id));
        $this->assertSame('20 - Operações / 20.01 - Transporte / 20.01.01 - Frota Própria', $grandchild->full_name);
        $this->assertSame($grandchild->full_name, ResultCenter::optionsForCompany($company->id)[$grandchild->id]);
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
        $company = $this->createCompany('Empresa Ciclo Resultado');
        $root = $this->createCenter($company->id, '1', 'Raiz');
        $child = $this->createCenter($company->id, '1.01', 'Filho', $root->id);
        $grandchild = $this->createCenter($company->id, '1.01.01', 'Neto', $child->id);

        $this->expectException(ValidationException::class);

        $root->update(['parent_id' => $grandchild->id]);
    }

    public function test_it_soft_deletes_center(): void
    {
        $company = $this->createCompany('Empresa Delete Resultado');
        $center = $this->createCenter($company->id, '1', 'Venda de Peças');

        $center->delete();

        $this->assertSoftDeleted('result_centers', ['id' => $center->id]);
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

    private function createCenter(int $companyId, string $code, string $name, ?int $parentId = null): ResultCenter
    {
        return ResultCenter::query()->create([
            'company_id' => $companyId,
            'parent_id' => $parentId,
            'code' => $code,
            'name' => $name,
            'is_active' => true,
            'sort_order' => 0,
        ]);
    }
}
