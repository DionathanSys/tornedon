<?php

namespace Tests\Feature\Services\FiscalDocument;

use App\Enum\FiscalDocument\DocumentModel;
use App\Enum\FiscalDocument\NfeStatus;
use App\Enum\FiscalDocument\NfseModel;
use App\Enum\FiscalDocument\Status;
use App\Models\Address;
use App\Models\Company;
use App\Models\CompanyPartner;
use App\Models\FiscalDocument;
use App\Models\FiscalProfile;
use App\Models\NfseSequence;
use App\Models\Partner;
use App\Models\User;
use App\Services\Fiscal\NfseConfigService;
use App\Services\FiscalDocument\NfseDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class NfsePreviewDoesNotPersistTemporaryRpsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_preview_builder_error_should_not_persist_temporary_rps_number(): void
    {
        [$user, $document] = $this->createDocumentWithoutItems();

        $this->assertNull($document->rps_number);
        $this->assertNull($document->rps_series);

        $config = Mockery::mock(NfseConfigService::class);
        $config->shouldReceive('resolveSerie')->once()->andReturn('1');
        $this->app->instance(NfseConfigService::class, $config);

        $service = app(NfseDocumentService::class);
        $result = $service->preview($document, $user->id);

        $this->assertNull($result);

        $document->refresh();

        $this->assertNull($document->rps_number);
        $this->assertNull($document->rps_series);
        $this->assertNotEmpty($document->errors_messages);
        $this->assertSame(0, NfseSequence::query()->count());
    }

    private function createDocumentWithoutItems(): array
    {
        $user = User::factory()->create();

        $company = Company::query()->create([
            'name' => 'Empresa Preview NFS-e',
            'document_number' => '99345678000100',
            'address' => [
                'city' => 'Chapeco',
                'state' => 'SC',
                'city_code' => '4204202',
            ],
            'created_by' => $user->id,
        ]);

        FiscalProfile::query()->create([
            'company_id' => $company->id,
            'tax_regime' => 'simples_nacional',
            'default_service_code' => '01.01',
            'default_municipal_tax_code' => '01.01',
            'default_nbs_code' => '123456789',
            'default_service_city_code' => '4204202',
            'iss_rate_default' => 5,
            'nfse_nacional_cst_default' => '01',
            'is_active' => true,
            'created_by' => $user->id,
        ]);

        $customer = Partner::query()->create([
            'name' => 'Cliente Preview NFS-e',
            'document_type' => 'CNPJ',
            'document_number' => '12345678000199',
            'created_by' => $user->id,
        ]);

        $companyPartner = CompanyPartner::query()->create([
            'company_id' => $company->id,
            'partner_id' => $customer->id,
            'type' => ['customer'],
            'is_active' => true,
        ]);

        Address::query()->create([
            'company_partner_id' => $companyPartner->id,
            'street' => 'Rua Teste',
            'number' => '100',
            'neighborhood' => 'Centro',
            'city' => 'Chapeco',
            'state' => 'SC',
            'postal_code' => '89800000',
            'city_code' => '4204202',
            'created_by' => $user->id,
        ]);

        $document = FiscalDocument::query()->create([
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'status' => Status::PENDING->value,
            'document_type' => DocumentModel::NFSE->value,
            'nfse_model' => NfseModel::NACIONAL->value,
            'issued_at' => now()->subDay()->toDateString(),
            'movement_at' => now()->subDay()->toDateString(),
            'nfse_status' => NfeStatus::PENDING->value,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        return [$user, $document];
    }
}
