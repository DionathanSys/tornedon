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
use App\Services\FiscalDocument\Actions\BuildNfseNacionalPayloadAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BuildNfseNacionalPayloadActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_uses_document_issued_at_instead_of_now(): void
    {
        $user = User::factory()->create();

        $company = Company::query()->create([
            'name' => 'Empresa Nacional',
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
            'name' => 'Cliente Nacional',
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
            'nfse_model' => NfseModel::NACIONAL->value,
            'issued_at' => now()->subDays(2)->toDateString(),
            'movement_at' => now()->subDay()->toDateString(),
            'rps_number' => '11',
            'rps_series' => '1',
            'created_by' => $user->id,
        ]);

        FiscalDocumentItem::query()->create([
            'fiscal_document_id' => $document->id,
            'item_number' => 1,
            'description' => 'Servico nacional',
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

        $payload = app(BuildNfseNacionalPayloadAction::class)->execute($document->fresh('items', 'company', 'customer', 'fiscalProfile'));

        $this->assertNotNull($payload);
        $this->assertStringStartsWith($document->issued_at->format('Y-m-d'), (string) data_get($payload, 'data_emissao'));
    }

    public function test_it_fails_when_rps_series_is_missing(): void
    {
        $user = User::factory()->create();

        $company = Company::query()->create([
            'name' => 'Empresa Nacional',
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
            'name' => 'Cliente Nacional',
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
            'nfse_model' => NfseModel::NACIONAL->value,
            'issued_at' => now()->subDays(2)->toDateString(),
            'movement_at' => now()->subDay()->toDateString(),
            'rps_number' => '11',
            'rps_series' => null,
            'created_by' => $user->id,
        ]);

        FiscalDocumentItem::query()->create([
            'fiscal_document_id' => $document->id,
            'item_number' => 1,
            'description' => 'Servico nacional',
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

        $action = app(BuildNfseNacionalPayloadAction::class);
        $payload = $action->execute($document->fresh('items', 'company', 'customer', 'fiscalProfile'));

        $this->assertNull($payload);
        $this->assertSame('NFS-e nacional requer série RPS válida para emissão.', $action->getMessage());
    }
}
