<?php

namespace Tests\Feature\Services\FiscalDocument\Actions;

use App\Enum\FiscalDocument\DocumentModel;
use App\Enum\FiscalDocument\NfseModel;
use App\Enum\FiscalDocument\Status;
use App\Models\Address;
use App\Models\Company;
use App\Models\CompanyPartner;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentItem;
use App\Models\FiscalProfile;
use App\Models\Partner;
use App\Models\User;
use App\Services\FiscalDocument\Actions\BuildNfsePayloadAction;
use App\Services\FiscalDocument\Resolvers\NfsePayloadBuilderResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BuildNfsePayloadActionResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_resolves_national_builder_correctly(): void
    {
        $document = $this->createDocument(NfseModel::NACIONAL);

        $resolver = app(NfsePayloadBuilderResolver::class);
        $builder = $resolver->resolve($document);
        $payload = app(BuildNfsePayloadAction::class)->execute($document);

        $this->assertNotNull($builder);
        $this->assertSame('nacional:default', $builder->identifier());
        $this->assertNotNull($payload);
        $this->assertSame('01.01', data_get($payload, 'servico.codigo'));
        $this->assertSame('123456789', data_get($payload, 'servico.codigo_nbs'));
    }

    public function test_it_falls_back_to_default_municipal_builder_when_city_specific_builder_is_missing(): void
    {
        $document = $this->createDocument(NfseModel::MUNICIPAL);

        $resolver = app(NfsePayloadBuilderResolver::class);
        $builder = $resolver->resolve($document);
        $payload = app(BuildNfsePayloadAction::class)->execute($document);

        $this->assertSame('municipal:4204202', $resolver->resolveKey($document));
        $this->assertNotNull($builder);
        $this->assertSame('municipal:default', $builder->identifier());
        $this->assertNotNull($payload);
        $this->assertSame(100.0, (float) data_get($payload, 'servico.valor_servicos'));
        $this->assertSame('01.01', data_get($payload, 'servico.codigo'));
    }

    private function createDocument(NfseModel $model): FiscalDocument
    {
        $user = User::factory()->create();

        $company = Company::query()->create([
            'name' => 'Empresa Resolver',
            'document_number' => '12345678000199',
            'address' => ['city' => 'Chapeco', 'state' => 'SC', 'city_code' => '4204202'],
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
            'is_active' => true,
            'created_by' => $user->id,
        ]);

        $customer = Partner::query()->create([
            'name' => 'Cliente Resolver',
            'document_type' => 'CNPJ',
            'document_number' => '22345678000188',
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
            'postal_code' => '89800-000',
            'city_code' => '4204202',
            'created_by' => $user->id,
        ]);

        $document = FiscalDocument::query()->create([
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'status' => Status::PENDING->value,
            'document_type' => DocumentModel::NFSE->value,
            'nfse_model' => $model->value,
            'issued_at' => now()->subDay()->toDateString(),
            'movement_at' => now()->subDay()->toDateString(),
            'rps_number' => '10',
            'rps_series' => '1',
            'created_by' => $user->id,
        ]);

        FiscalDocumentItem::query()->create([
            'fiscal_document_id' => $document->id,
            'item_number' => 1,
            'description' => 'Servico resolver',
            'quantity' => 1,
            'unit_of_measure' => 'UN',
            'unit_price' => 100,
            'total_price' => 100,
            'municipal_tax_code' => '01.01',
            'nbs_code' => '123456789',
            'iss_exigibility' => '1',
            'iss_rate' => 5,
            'included_in_total' => true,
            'created_by' => $user->id,
        ]);

        return $document->fresh('items', 'company', 'customer', 'fiscalProfile');
    }
}
