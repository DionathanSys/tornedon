<?php

namespace Tests\Feature\Commitments;

use App\Enum\Commitment\ImportType;
use App\Filament\Clusters\Financial\Resources\CommitmentImports\CommitmentImportResource;
use App\Filament\Clusters\Financial\Resources\CommitmentImports\Pages\ListCommitmentImports;
use App\Filament\Clusters\Financial\Resources\CommitmentImports\Pages\ViewCommitmentImport;
use App\Filament\Clusters\Financial\Resources\CommitmentImports\RelationManagers\FilesRelationManager;
use App\Filament\Clusters\Financial\Resources\CommitmentPaymentImports\CommitmentPaymentImportResource;
use App\Filament\Clusters\Financial\Resources\CommitmentPaymentImports\Pages\ListCommitmentPaymentImports;
use App\Models\CommitmentImport;
use App\Models\CommitmentSetting;
use App\Models\Company;
use App\Models\Partner;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class CommitmentImportFilamentTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Partner $customer;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('local');
        $this->company = Company::factory()->create();
        $this->customer = Partner::factory()->create();
        $this->company->partners()->attach($this->customer, ['type' => json_encode(['customer']), 'is_active' => true]);
        $this->user = User::factory()->create();
        $this->user->companies()->attach($this->company, ['role' => 'member', 'is_active' => true]);
        $this->actingAs($this->user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($this->company);
    }

    public function test_resources_are_registered_and_lists_separate_types_and_tenants(): void
    {
        $this->assertContains(CommitmentImportResource::class, Filament::getPanel('admin')->getResources());
        $this->assertContains(CommitmentPaymentImportResource::class, Filament::getPanel('admin')->getResources());
        $commitments = $this->batch(ImportType::COMMITMENTS);
        $payments = $this->batch(ImportType::PAYMENTS);
        $other = Company::factory()->create();
        $other->partners()->attach($this->customer, ['type' => json_encode(['customer'])]);
        $foreign = $this->batch(ImportType::COMMITMENTS, $other);
        Livewire::test(ListCommitmentImports::class)->assertSuccessful()->assertCanSeeTableRecords([$commitments])->assertCanNotSeeTableRecords([$payments, $foreign]);
        Livewire::test(ListCommitmentPaymentImports::class)->assertSuccessful()->assertCanSeeTableRecords([$payments])->assertCanNotSeeTableRecords([$commitments, $foreign]);
    }

    public function test_import_links_are_visible_in_the_existing_financial_navigation_group(): void
    {
        $financial = collect(Filament::getNavigation())->first(fn ($group) => $group->getLabel() === 'Financeiro');
        $this->assertNotNull($financial);
        $items = collect($financial->getItems())->keyBy(fn ($item) => $item->getLabel());
        $this->assertTrue($items->has('Empenhos'), 'O menu Financeiro deve conter o acesso a Empenhos.');
        $this->assertTrue($items->has('Pagamentos de empenhos'));
        $this->assertSame(CommitmentImportResource::getUrl('index'), $items['Empenhos']->getUrl());
        $this->assertSame(CommitmentPaymentImportResource::getUrl('index'), $items['Pagamentos de empenhos']->getUrl());
    }

    public function test_configuration_action_can_enable_the_import_flow(): void
    {
        Livewire::test(ListCommitmentImports::class)->callAction('configure_customer', [
            'customer_id' => $this->customer->id, 'is_enabled' => true,
            'nfse_footer_message' => 'Mensagem fixa',
        ])->assertHasNoActionErrors();
        $this->assertDatabaseHas('commitment_settings', ['company_id' => $this->company->id, 'customer_id' => $this->customer->id, 'is_enabled' => true]);
    }

    public function test_upload_action_registers_batch_and_redirects_to_its_history(): void
    {
        Exceptions::fake();
        CommitmentSetting::create(['company_id' => $this->company->id, 'customer_id' => $this->customer->id, 'is_enabled' => true]);
        Livewire::test(ListCommitmentImports::class)->callAction('import_pdfs', [
            'customer_id' => $this->customer->id,
            'files' => [UploadedFile::fake()->createWithContent('empenho.pdf', "%PDF-1.4\nfixture")],
        ])->assertHasNoActionErrors()->assertRedirect();
        Exceptions::throwFirstReported();
        $summary = CommitmentImport::firstOrFail()->summary;
        $this->assertSame('accepted', $summary[0]['status'], json_encode($summary));
        $this->assertDatabaseCount('commitment_import_files', 1);
    }

    public function test_detail_and_file_manager_show_errors_and_extracted_data(): void
    {
        $import = $this->batch(ImportType::COMMITMENTS);
        $file = $import->files()->create([
            'company_id' => $this->company->id, 'customer_id' => $this->customer->id,
            'type' => ImportType::COMMITMENTS, 'sha256' => hash('sha256', 'one'),
            'original_name' => 'example.pdf', 'status' => 'needs_review',
            'extracted_data' => ['number' => '14728', 'fiscal_year' => 2026, 'patrimony' => '142186', 'object' => 'Manutenção', 'issues' => ['Revisar patrimônio'], 'items' => []],
        ]);
        Livewire::test(ViewCommitmentImport::class, ['record' => $import->getRouteKey()])->assertSuccessful();
        $component = Livewire::test(FilesRelationManager::class, ['ownerRecord' => $import, 'pageClass' => ViewCommitmentImport::class])
            ->assertCanSeeTableRecords([$file])->mountAction(TestAction::make('details')->table($file))
            ->assertActionMounted(TestAction::make('details')->table($file));
        $content = $component->instance()->getMountedAction()->getModalContent()->render();
        $this->assertStringContainsString('Revisar patrimônio', $content);
        $this->assertStringContainsString('142186', $content);
    }

    public function test_financial_permission_is_required_to_open_import_pages(): void
    {
        $this->user->companyAccesses()->create(['company_id' => $this->company->id, 'permissions' => ['cash_movements.access'], 'panels' => ['admin' => true], 'navigation' => []]);
        Livewire::test(ListCommitmentImports::class)->assertForbidden();
        $labels = collect(Filament::getNavigation())->flatMap(fn ($group) => collect($group->getItems())->map(fn ($item) => $item->getLabel()));
        $this->assertNotContains('Empenhos', $labels);
        $this->assertNotContains('Pagamentos de empenhos', $labels);
    }

    public function test_cross_tenant_detail_is_not_resolved(): void
    {
        $other = Company::factory()->create();
        $other->partners()->attach($this->customer, ['type' => json_encode(['customer'])]);
        $foreign = $this->batch(ImportType::COMMITMENTS, $other);
        $this->expectException(ModelNotFoundException::class);
        Livewire::test(ViewCommitmentImport::class, ['record' => $foreign->getRouteKey()]);
    }

    private function batch(ImportType $type, ?Company $company = null): CommitmentImport
    {
        return CommitmentImport::create(['company_id' => ($company ?? $this->company)->id, 'customer_id' => $this->customer->id, 'type' => $type, 'summary' => []]);
    }
}
