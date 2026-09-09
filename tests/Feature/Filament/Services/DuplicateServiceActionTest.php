<?php

namespace Tests\Feature\Filament\Services;

use App\Enum\Tax\IssExigibility;
use App\Filament\Clusters\Sales\Resources\Services\Pages\EditService;
use App\Filament\Clusters\Sales\Resources\Services\ServiceResource;
use App\Models\Company;
use App\Models\Service;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class DuplicateServiceActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $compiledPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tornedon-views-test-'.Str::uuid();
        File::ensureDirectoryExists($compiledPath);

        config(['view.compiled' => $compiledPath]);

        $bladeCompiler = app('blade.compiler');
        $reflection = new \ReflectionClass($bladeCompiler);
        $cachePath = $reflection->getProperty('cachePath');
        $cachePath->setAccessible(true);
        $cachePath->setValue($bladeCompiler, $compiledPath);
    }

    public function test_it_duplicates_a_service_with_a_new_name_and_code(): void
    {
        $user = User::factory()->create();
        $company = Company::query()->create([
            'name' => 'Empresa de Serviços',
            'address' => ['city' => 'São Paulo', 'state' => 'SP'],
            'email' => 'empresa-servicos@example.com',
            'is_active' => true,
            'created_by' => $user->id,
        ]);
        $user->companies()->attach($company, [
            'role' => 'admin',
            'is_active' => true,
        ]);

        $service = Service::query()->create([
            'company_id' => $company->id,
            'service_code' => 'SRV-001',
            'name' => 'Instalação de equipamento',
            'description' => 'Instalação completa no local do cliente.',
            'price' => 150.50,
            'min_sale_price' => 100.00,
            'accept_customer_discount' => true,
            'cost' => 50.25,
            'category' => 'Instalação',
            'is_active' => true,
            'requires_approval' => true,
            'tax_classification' => '14.01',
            'tax_rate' => 2.50,
            'nbs_code' => '10101010',
            'cnae_code' => '4321500',
            'municipal_tax_code' => '14.01',
            'iss_exigibility' => IssExigibility::EXIGIVEL,
            'ncm_code' => '00000000',
            'cfop_code' => '5933',
            'origin_code' => '07',
            'unit_of_measure' => 'UN',
            'additional_info' => ['prazo' => '2 dias'],
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $this->actingAs($user);
        Filament::setCurrentPanel('admin');
        Filament::setTenant($company);

        $component = Livewire::test(EditService::class, [
            'record' => (string) $service->getRouteKey(),
        ])
            ->assertActionExists('duplicateService')
            ->assertActionVisible('duplicateService')
            ->callAction('duplicateService', data: [
                'name' => 'Instalação de equipamento premium',
            ])
            ->assertHasNoActionErrors();

        $duplicated = Service::query()
            ->where('company_id', $company->id)
            ->where('name', 'Instalação de equipamento premium')
            ->first();

        $this->assertNotNull($duplicated);
        $this->assertNotSame($service->id, $duplicated->id);
        $this->assertSame('00001', $duplicated->service_code);
        $this->assertSame($service->description, $duplicated->description);
        $this->assertSame($service->price, $duplicated->price);
        $this->assertSame($service->min_sale_price, $duplicated->min_sale_price);
        $this->assertSame($service->accept_customer_discount, $duplicated->accept_customer_discount);
        $this->assertSame($service->cost, $duplicated->cost);
        $this->assertSame($service->category, $duplicated->category);
        $this->assertSame($service->is_active, $duplicated->is_active);
        $this->assertSame($service->requires_approval, $duplicated->requires_approval);
        $this->assertSame($service->tax_classification, $duplicated->tax_classification);
        $this->assertSame($service->tax_rate, $duplicated->tax_rate);
        $this->assertSame($service->nbs_code, $duplicated->nbs_code);
        $this->assertSame($service->cnae_code, $duplicated->cnae_code);
        $this->assertSame($service->municipal_tax_code, $duplicated->municipal_tax_code);
        $this->assertSame($service->iss_exigibility, $duplicated->iss_exigibility);
        $this->assertSame($service->ncm_code, $duplicated->ncm_code);
        $this->assertSame($service->cfop_code, $duplicated->cfop_code);
        $this->assertSame($service->origin_code, $duplicated->origin_code);
        $this->assertSame($service->unit_of_measure, $duplicated->unit_of_measure);
        $this->assertSame($service->additional_info, $duplicated->additional_info);
        $this->assertSame($user->id, $duplicated->created_by);
        $this->assertNull($duplicated->updated_by);
        $this->assertNull($duplicated->deleted_at);

        $component->assertRedirect(ServiceResource::getUrl('edit', ['record' => $duplicated]));
    }
}
