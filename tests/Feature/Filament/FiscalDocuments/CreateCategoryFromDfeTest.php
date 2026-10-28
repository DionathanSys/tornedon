<?php

namespace Tests\Feature\Filament\FiscalDocuments;

use App\Enum\SefazDistributionDocument\ManifestationStatus;
use App\Enum\SefazDistributionDocument\Status;
use App\Filament\Clusters\Financial\Resources\SefazDistributionDocuments\Pages\ViewSefazDistributionDocument;
use App\Models\Category;
use App\Models\Company;
use App\Models\SefazDistributionDocument;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CreateCategoryFromDfeTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $company;

    private SefazDistributionDocument $document;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->company = Company::query()->create([
            'name' => 'Empresa DF-e',
            'document_number' => '98765432000199',
            'address' => ['city' => 'São Paulo', 'state' => 'SP'],
            'email' => 'dfe@example.com',
            'is_active' => true,
            'created_by' => $this->user->id,
        ]);
        $this->user->companies()->attach($this->company, ['role' => 'admin', 'is_active' => true]);
        $this->document = SefazDistributionDocument::query()->create([
            'company_id' => $this->company->id,
            'document_key' => str_repeat('1', 44),
            'status' => Status::FULL_XML_AVAILABLE,
            'manifestation_status' => ManifestationStatus::ACCEPTED,
            'items_json' => [['line' => 1, 'description' => 'Produto XML', 'unit' => 'UN']],
        ]);

        $this->actingAs($this->user);
        Filament::setCurrentPanel('admin');
        Filament::setTenant($this->company);
    }

    public function test_it_creates_and_selects_an_active_category_in_the_document_company(): void
    {
        $page = Livewire::test(ViewSefazDistributionDocument::class, ['record' => $this->document->getRouteKey()])
            ->mountAction('createProductFromXml')
            ->callAction(TestAction::make('createOption')->schemaComponent('category_id', 'mountedActionSchema0'), data: [
                'name' => 'Material de consumo',
                'description' => 'Categoria criada no XML',
            ])
            ->assertHasNoActionErrors();

        $category = Category::query()->where('name', 'Material de consumo')->sole();
        $this->assertSame($this->company->id, $category->company_id);
        $this->assertTrue($category->is_active);
        $this->assertSame($this->user->id, $category->created_by);
        $this->assertSame($this->user->id, $category->updated_by);
        $this->assertSame('Categoria criada no XML', $category->description);
        $page->assertSet('mountedActions.0.data.category_id', $category->id);
    }

    public function test_it_rejects_a_duplicate_even_when_category_and_document_have_the_same_id(): void
    {
        Category::query()->create([
            'id' => $this->document->id,
            'name' => 'Material de consumo',
            'company_id' => $this->company->id,
            'is_active' => true,
        ]);

        Livewire::test(ViewSefazDistributionDocument::class, ['record' => $this->document->getRouteKey()])
            ->mountAction('createProductFromXml')
            ->callAction(TestAction::make('createOption')->schemaComponent('category_id', 'mountedActionSchema0'), data: [
                'name' => 'Material de consumo',
            ])
            ->assertHasActionErrors(['name' => 'unique']);

        $this->assertSame(1, Category::query()->count());
    }

    public function test_the_same_name_in_another_company_does_not_block_creation(): void
    {
        $otherCompany = Company::query()->create([
            'name' => 'Outra empresa',
            'document_number' => '12345678000199',
            'address' => ['city' => 'São Paulo', 'state' => 'SP'],
            'email' => 'outra@example.com',
            'created_by' => $this->user->id,
        ]);
        Category::query()->create([
            'name' => 'Material de consumo',
            'company_id' => $otherCompany->id,
            'is_active' => true,
        ]);

        Livewire::test(ViewSefazDistributionDocument::class, ['record' => $this->document->getRouteKey()])
            ->mountAction('createProductFromXml')
            ->callAction(TestAction::make('createOption')->schemaComponent('category_id', 'mountedActionSchema0'), data: [
                'name' => 'Material de consumo',
            ])
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('categories', [
            'name' => 'Material de consumo',
            'company_id' => $this->company->id,
        ]);
        $this->assertSame(2, Category::query()->count());
    }
}
