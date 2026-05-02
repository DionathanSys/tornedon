<?php

namespace Tests\Feature\Services\PurchaseClosing;

use App\Enum\FiscalDocument\DocumentModel;
use App\Enum\FiscalDocument\OperationType;
use App\Enum\FiscalDocument\Status as FiscalDocumentStatus;
use App\Enum\Payment\Method;
use App\Enum\Product\OriginSalePrice;
use App\Enum\Product\Unit;
use App\Enum\PurchaseClosing\Status as PurchaseClosingStatus;
use App\Models\Company;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentItem;
use App\Models\Partner;
use App\Models\Product;
use App\Models\User;
use App\Services\AccountPayable\AccountPayableService;
use App\Services\PurchaseClosing\PurchaseClosingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseClosingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_purchase_closing_calculates_discount_and_net_amount(): void
    {
        [$user, $company, $supplier] = $this->baseContext();
        $firstDocument = $this->createFiscalDocument($company, $supplier, $user, 'NF-PC-001', '2026-05-01', 100);
        $secondDocument = $this->createFiscalDocument($company, $supplier, $user, 'NF-PC-002', '2026-05-05', 50);

        $service = app(PurchaseClosingService::class);
        $closing = $service->create([
            'company_id' => $company->id,
            'supplier_id' => $supplier->id,
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-31',
            'reference' => 'FECH-05/2026',
            'documents' => [
                ['fiscal_document_id' => $firstDocument->id, 'discount_amount' => 10],
                ['fiscal_document_id' => $secondDocument->id, 'discount_amount' => 5],
            ],
        ], $user->id);

        $this->assertNotNull($closing, $service->getMessageUser());
        $this->assertSame(PurchaseClosingStatus::DRAFT, $closing->status);
        $this->assertEquals(150.0, (float) $closing->gross_amount);
        $this->assertEquals(15.0, (float) $closing->discount_amount);
        $this->assertEquals(135.0, (float) $closing->net_amount);
        $this->assertDatabaseCount('purchase_closings', 1);
        $this->assertDatabaseCount('purchase_closing_fiscal_documents', 2);
    }

    public function test_cannot_link_same_fiscal_document_to_another_purchase_closing(): void
    {
        [$user, $company, $supplier] = $this->baseContext();
        $document = $this->createFiscalDocument($company, $supplier, $user, 'NF-PC-003', '2026-05-10', 120);
        $service = app(PurchaseClosingService::class);

        $firstClosing = $service->create([
            'company_id' => $company->id,
            'supplier_id' => $supplier->id,
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-31',
            'documents' => [
                ['fiscal_document_id' => $document->id, 'discount_amount' => 0],
            ],
        ], $user->id);

        $this->assertNotNull($firstClosing, $service->getMessageUser());

        $secondClosing = $service->create([
            'company_id' => $company->id,
            'supplier_id' => $supplier->id,
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-31',
            'documents' => [
                ['fiscal_document_id' => $document->id, 'discount_amount' => 0],
            ],
        ], $user->id);

        $this->assertNull($secondClosing);
        $this->assertTrue($service->hasError());
        $this->assertStringContainsString('já pertence a outro fechamento', $service->getMessageUser());
    }

    public function test_reopen_requires_account_payable_deletion_before_state_change(): void
    {
        [$user, $company, $supplier] = $this->baseContext();
        $document = $this->createFiscalDocument($company, $supplier, $user, 'NF-PC-004', '2026-05-15', 200);
        $service = app(PurchaseClosingService::class);

        $closing = $service->create([
            'company_id' => $company->id,
            'supplier_id' => $supplier->id,
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-31',
            'documents' => [
                ['fiscal_document_id' => $document->id, 'discount_amount' => 20],
            ],
        ], $user->id);

        $this->assertNotNull($closing, $service->getMessageUser());

        $payable = $service->generateAccountPayable($closing, [
            'due_date' => '2026-06-10',
            'payment_method' => Method::PIX->value,
            'description' => 'Fechamento maio',
        ], $user->id);

        $this->assertNotNull($payable, $service->getMessageUser());

        $reopenedWhileLinked = $service->reopen($closing->fresh(), $user->id);
        $this->assertNull($reopenedWhileLinked);
        $this->assertStringContainsString('Exclua a conta a pagar vinculada', $service->getMessageUser());

        $payableService = app(AccountPayableService::class);
        $deleted = $payableService->delete($payable->fresh());
        $this->assertTrue($deleted, $payableService->getMessageUser());

        $reopened = $service->reopen($closing->fresh(), $user->id);
        $this->assertNotNull($reopened, $service->getMessageUser());
        $this->assertSame(PurchaseClosingStatus::REOPENED, $reopened->status);
        $this->assertNull($reopened->account_payable_id);
    }

    /**
     * @return array{User, Company, Partner}
     */
    private function baseContext(): array
    {
        $user = User::factory()->create();

        $company = Company::create([
            'name' => 'Empresa Fechamento',
            'document_number' => '12345678000199',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'created_by' => $user->id,
        ]);

        $supplier = Partner::create([
            'name' => 'Fornecedor Fechamento',
            'document_type' => 'CNPJ',
            'document_number' => '33345678000155',
            'created_by' => $user->id,
        ]);

        return [$user, $company, $supplier];
    }

    private function createFiscalDocument(Company $company, Partner $supplier, User $user, string $number, string $issuedAt, float $amount): FiscalDocument
    {
        $product = Product::query()->create([
            'company_id' => $company->id,
            'created_by' => $user->id,
            'product_code' => 'PC-' . str_replace('-', '', $number),
            'name' => 'Produto ' . $number,
            'unit' => Unit::UN->value,
            'has_stock_control' => false,
            'origin_sale_price' => OriginSalePrice::FREE->value,
            'sale_price_value' => $amount,
            'is_active' => true,
        ]);

        $document = FiscalDocument::create([
            'customer_id' => $supplier->id,
            'company_id' => $company->id,
            'status' => FiscalDocumentStatus::CONFIRMED->value,
            'confirmed' => true,
            'confirmed_at' => $issuedAt,
            'confirmed_by' => $user->id,
            'issued_at' => $issuedAt,
            'movement_at' => $issuedAt,
            'document_type' => DocumentModel::NFE->value,
            'operation_type' => OperationType::ENTRADA->value,
            'document_number' => $number,
            'document_series' => '1',
            'created_by' => $user->id,
        ]);

        FiscalDocumentItem::create([
            'fiscal_document_id' => $document->id,
            'product_id' => $product->id,
            'product_code' => $product->product_code,
            'description' => $product->name,
            'item_number' => 1,
            'product_origin' => '0',
            'ncm_code' => '84733049',
            'cfop_code' => null,
            'quantity' => 1,
            'unit_of_measure' => Unit::UN->value,
            'unit_price' => $amount,
            'total_price' => $amount,
            'included_in_total' => true,
            'created_by' => $user->id,
        ]);

        return $document;
    }
}
