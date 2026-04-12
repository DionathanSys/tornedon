<?php

namespace Tests\Feature\Services\FiscalDocument;

use App\Enum\FiscalDocument\BuyerPresenceIndicator;
use App\Enum\FiscalDocument\DocumentModel;
use App\Enum\FiscalDocument\FreightModality;
use App\Enum\FiscalDocument\IssuePurpose;
use App\Enum\FiscalDocument\NfeStatus;
use App\Enum\FiscalDocument\NfseModel;
use App\Enum\FiscalDocument\OperationNature;
use App\Enum\FiscalDocument\OperationType;
use App\Models\Company;
use App\Models\FiscalProfile;
use App\Models\Partner;
use App\Models\User;
use App\Services\FiscalDocument\FiscalDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FiscalDocumentServiceCreateTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_initializes_nfe_status_as_pending_when_creating_an_nfe_document(): void
    {
        $user = User::factory()->create();
        $company = Company::create([
            'name' => 'Empresa NF-e',
            'document_number' => '12345678000199',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'created_by' => $user->id,
        ]);
        $customer = Partner::create([
            'name' => 'Cliente NF-e',
            'document_type' => 'CNPJ',
            'document_number' => '22345678000188',
            'created_by' => $user->id,
        ]);

        FiscalProfile::create([
            'company_id' => $company->id,
            'tax_regime' => 'simples_nacional',
            'cfop_rules' => [
                OperationNature::VENDA_DENTRO_ESTADO->value => [
                    'default_cfop' => '5102',
                ],
            ],
            'is_active' => true,
            'created_by' => $user->id,
        ]);

        $service = app(FiscalDocumentService::class);
        $document = $service->create([
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'document_type' => DocumentModel::NFE->value,
            'issued_at' => now()->toDateString(),
            'movement_at' => now()->toDateString(),
            'operation_nature' => OperationNature::VENDA_DENTRO_ESTADO->value,
            'operation_type' => OperationType::SAIDA->value,
            'issue_purpose' => IssuePurpose::NORMAL->value,
            'is_final_consumer' => true,
            'buyer_presence_indicator' => BuyerPresenceIndicator::NAO_SE_APLICA->value,
            'freight_data' => [
                'modalidade_frete' => FreightModality::SEM_FRETE->value,
            ],
        ], $user->id);

        $this->assertNotNull($document, $service->getMessage());
        $this->assertSame(NfeStatus::PENDING, $document->nfe_status);
        $this->assertNull($document->nfse_status);
    }

    public function test_it_initializes_nfse_status_as_pending_when_creating_an_nfse_document(): void
    {
        $user = User::factory()->create();
        $company = Company::create([
            'name' => 'Empresa NFS-e',
            'document_number' => '32345678000177',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'created_by' => $user->id,
        ]);
        $customer = Partner::create([
            'name' => 'Cliente NFS-e',
            'document_type' => 'CNPJ',
            'document_number' => '42345678000166',
            'created_by' => $user->id,
        ]);

        $service = app(FiscalDocumentService::class);
        $document = $service->create([
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'document_type' => DocumentModel::NFSE->value,
            'issued_at' => now()->toDateString(),
            'movement_at' => now()->toDateString(),
            'nfse_model' => NfseModel::MUNICIPAL->value,
        ], $user->id);

        $this->assertNotNull($document, $service->getMessage());
        $this->assertSame(NfeStatus::PENDING, $document->nfse_status);
        $this->assertNull($document->nfe_status);
    }
}
