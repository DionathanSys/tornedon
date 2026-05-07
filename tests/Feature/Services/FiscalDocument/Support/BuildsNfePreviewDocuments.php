<?php

namespace Tests\Feature\Services\FiscalDocument\Support;

use App\Enum\FiscalDocument\BuyerPresenceIndicator;
use App\Enum\FiscalDocument\DocumentModel;
use App\Enum\FiscalDocument\FreightModality;
use App\Enum\FiscalDocument\IssuePurpose;
use App\Enum\FiscalDocument\NfeStatus;
use App\Enum\FiscalDocument\OperationNature;
use App\Enum\FiscalDocument\OperationType;
use App\Enum\FiscalDocument\Status;
use App\Models\Address;
use App\Models\Company;
use App\Models\CompanyPartner;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentItem;
use App\Models\FiscalProfile;
use App\Models\Partner;
use App\Models\Product;
use App\Models\User;

trait BuildsNfePreviewDocuments
{
    private function createReadyPreviewDocument(
        ?Company $company = null,
        ?Partner $customer = null,
        ?User $user = null,
        bool $withItemNcm = true,
        bool $withCustomerAddress = true,
    ): array {
        $user ??= User::factory()->create();

        $company ??= Company::query()->create([
            'name' => 'Empresa Preview',
            'document_number' => '12345678000199',
            'address' => [
                'city' => 'Sao Paulo',
                'state' => 'SP',
            ],
            'created_by' => $user->id,
        ]);

        FiscalProfile::query()->firstOrCreate(
            ['company_id' => $company->id],
            [
                'tax_regime' => 'simples_nacional',
                'cfop_rules' => [
                    OperationNature::VENDA_DENTRO_ESTADO->value => [
                        'default_cfop' => '5102',
                    ],
                ],
                'is_active' => true,
                'created_by' => $user->id,
            ],
        );

        $customer ??= Partner::query()->create([
            'name' => 'Cliente Preview',
            'document_type' => 'CNPJ',
            'document_number' => '22345678000188',
            'state_tax_id' => '110042490114',
            'created_by' => $user->id,
        ]);

        $companyPartner = CompanyPartner::query()->firstOrCreate(
            [
                'company_id' => $company->id,
                'partner_id' => $customer->id,
            ],
            [
                'type' => ['customer'],
                'is_active' => true,
            ],
        );

        if ($withCustomerAddress) {
            Address::query()->firstOrCreate(
                ['company_partner_id' => $companyPartner->id],
                [
                    'street' => 'Rua Teste',
                    'number' => '100',
                    'neighborhood' => 'Centro',
                    'city' => 'Sao Paulo',
                    'state' => 'SP',
                    'postal_code' => '01000-000',
                    'city_code' => '3550308',
                    'created_by' => $user->id,
                ],
            );
        }

        $product = Product::query()->create([
            'company_id' => $company->id,
            'product_code' => 'PRD-PREVIEW-001',
            'name' => 'Produto Preview',
            'unit' => 'UN',
            'sale_price_value' => 100,
            'is_active' => true,
            'created_by' => $user->id,
        ]);

        $document = FiscalDocument::query()->create([
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'status' => Status::PENDING->value,
            'document_type' => DocumentModel::NFE->value,
            'issued_at' => now()->subDay()->toDateString(),
            'movement_at' => now()->subDay()->toDateString(),
            'operation_nature' => OperationNature::VENDA_DENTRO_ESTADO->value,
            'operation_type' => OperationType::SAIDA->value,
            'issue_purpose' => IssuePurpose::NORMAL->value,
            'is_final_consumer' => false,
            'buyer_presence_indicator' => BuyerPresenceIndicator::OUTROS->value,
            'freight_data' => [
                'modalidade_frete' => FreightModality::SEM_FRETE->value,
            ],
            'nfe_status' => NfeStatus::PENDING->value,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        FiscalDocumentItem::query()->create([
            'fiscal_document_id' => $document->id,
            'product_id' => $product->id,
            'product_code' => $product->product_code,
            'description' => $product->name,
            'item_number' => 1,
            'product_origin' => '0',
            'ncm_code' => $withItemNcm ? '84733049' : null,
            'cfop_code' => '5102',
            'quantity' => 1,
            'unit_of_measure' => 'UN',
            'unit_price' => 100,
            'total_price' => 100,
            'included_in_total' => true,
            'tax_data' => [
                'imposto' => [
                    'icms' => ['situacao_tributaria' => '102'],
                    'pis' => ['situacao_tributaria' => '01'],
                    'cofins' => ['situacao_tributaria' => '01'],
                ],
            ],
            'created_by' => $user->id,
        ]);

        return [$user, $document];
    }
}
