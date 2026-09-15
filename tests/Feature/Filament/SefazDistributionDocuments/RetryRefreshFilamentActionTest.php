<?php

namespace Tests\Feature\Filament\SefazDistributionDocuments;

use App\Enum\SefazDistributionDocument\ImportStatus;
use App\Enum\SefazDistributionDocument\ManifestationStatus;
use App\Enum\SefazDistributionDocument\Status;
use App\Filament\Clusters\Financial\Resources\SefazDistributionDocuments\Pages\ViewSefazDistributionDocument;
use App\Jobs\RefreshSefazDistributionDocumentJob;
use App\Models\Company;
use App\Models\SefazDistributionDocument;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class RetryRefreshFilamentActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_action_is_visible_and_dispatches_refresh_when_the_full_xml_file_is_missing(): void
    {
        Bus::fake();
        Storage::fake('local');

        [$user, $company] = $this->createCompanyWithUser();
        $document = $this->createDocument($company, [
            'full_xml_available' => true,
            'full_xml_path' => 'sefaz/distribution/company-'.$company->id.'/missing.xml',
            'status' => Status::FULL_XML_AVAILABLE,
            'manifestation_status' => ManifestationStatus::ACCEPTED,
        ]);

        $this->setCurrentTenant($user, $company);

        Livewire::test(ViewSefazDistributionDocument::class, [
            'record' => (string) $document->getRouteKey(),
        ])
            ->assertActionExists('retryRefresh')
            ->assertActionVisible('retryRefresh')
            ->callAction('retryRefresh');

        $document->refresh();

        $this->assertSame('manual_refresh_requested', $document->last_action);
        Bus::assertDispatched(RefreshSefazDistributionDocumentJob::class);
        $this->assertDatabaseHas('audit_entries', [
            'company_id' => $company->id,
            'auditable_type' => SefazDistributionDocument::class,
            'auditable_id' => $document->id,
            'event' => 'sefaz_distribution.reprocessed',
            'summary' => 'Busca manual do XML completo solicitada',
        ]);
    }

    public function test_action_is_hidden_when_the_full_xml_file_exists(): void
    {
        Storage::fake('local');

        [$user, $company] = $this->createCompanyWithUser();
        $path = 'sefaz/distribution/company-'.$company->id.'/document/full.xml';
        Storage::disk('local')->put($path, '<procNFe/>');

        $document = $this->createDocument($company, [
            'full_xml_available' => true,
            'full_xml_path' => $path,
            'status' => Status::FULL_XML_AVAILABLE,
            'manifestation_status' => ManifestationStatus::ACCEPTED,
        ]);

        $this->setCurrentTenant($user, $company);

        Livewire::test(ViewSefazDistributionDocument::class, [
            'record' => (string) $document->getRouteKey(),
        ])
            ->assertActionExists('retryRefresh')
            ->assertActionHidden('retryRefresh');
    }

    /**
     * @return array{0: User, 1: Company}
     */
    private function createCompanyWithUser(): array
    {
        $user = User::factory()->create();
        $company = Company::query()->create([
            'name' => 'Empresa DF-e '.Str::uuid(),
            'document_number' => '12345678000199',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'email' => Str::uuid().'@example.com',
            'certificate' => 'certificados/teste.pfx',
            'is_active' => true,
            'created_by' => $user->id,
        ]);

        $user->companies()->attach($company, [
            'role' => 'admin',
            'is_active' => true,
        ]);

        return [$user, $company];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createDocument(Company $company, array $overrides = []): SefazDistributionDocument
    {
        return SefazDistributionDocument::query()->create(array_merge([
            'company_id' => $company->id,
            'document_key' => '35260412345678000199550010000003211000000321',
            'nsu' => '000000000000050',
            'schema' => 'procNFe_v4.00.xsd',
            'document_type' => 'nfe',
            'status' => Status::MANIFESTED_WAITING_FULL_XML,
            'manifestation_status' => ManifestationStatus::ACCEPTED,
            'full_xml_available' => false,
            'import_status' => ImportStatus::PENDING_XML,
            'last_seen_at' => now(),
        ], $overrides));
    }

    private function setCurrentTenant(User $user, Company $company): void
    {
        $this->actingAs($user);
        Filament::setCurrentPanel('admin');
        Filament::setTenant($company);
    }
}
