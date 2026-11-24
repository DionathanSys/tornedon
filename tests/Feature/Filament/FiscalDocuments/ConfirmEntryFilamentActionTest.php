<?php

namespace Tests\Feature\Filament\FiscalDocuments;

use App\Enum\Financial\FinancialAccountType;
use App\Enum\FiscalDocument\DocumentModel;
use App\Enum\FiscalDocument\OperationType;
use App\Enum\FiscalDocument\Status;
use App\Enum\Payment\Method;
use App\Enum\Product\OriginSalePrice;
use App\Enum\Product\Unit;
use App\Filament\Clusters\Financial\Resources\FiscalDocuments\Pages\EditFiscalDocument;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\FinancialAccount;
use App\Models\FinancialCategory;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentItem;
use App\Models\Partner;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\ResultCenter;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\Financial\InstallmentSchedule;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ConfirmEntryFilamentActionTest extends TestCase
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

    public static function payableSchedules(): array
    {
        return [
            'parcela única não efetivada' => [1, null, null, false, ['2027-01-31'], [100.0]],
            'dia fixo e arredondamento' => [3, InstallmentSchedule::FIXED_DAY_OF_MONTH, 31, true,
                ['2027-01-31', '2027-02-28', '2027-03-31'], [33.33, 33.33, 33.34]],
            'intervalo personalizado' => [2, InstallmentSchedule::CUSTOM_INTERVAL_DAYS, 15, false,
                ['2027-01-31', '2027-02-15'], [50.0, 50.0]],
        ];
    }

    #[DataProvider('payableSchedules')]
    public function test_confirm_entry_uses_standalone_payable_options(
        int $count, ?string $mode, ?int $interval, bool $effective, array $dates, array $amounts,
    ): void {
        $user = User::factory()->create();
        $company = Company::create([
            'name' => 'Empresa Entrada Financeiro', 'document_number' => '12345678000191',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'], 'created_by' => $user->id,
        ]);
        $supplier = Partner::create([
            'name' => 'Fornecedor Entrada', 'document_type' => 'CNPJ',
            'document_number' => '22345678000181', 'created_by' => $user->id,
        ]);
        $supplier->companies()->attach($company, ['is_active' => true, 'type' => '["supplier"]']);
        $document = FiscalDocument::create([
            'customer_id' => $supplier->id, 'company_id' => $company->id,
            'status' => Status::PENDING->value, 'issued_at' => '2027-01-01', 'movement_at' => '2027-01-01',
            'document_type' => DocumentModel::NFE->value, 'operation_type' => OperationType::ENTRADA->value,
            'document_number' => 'NF-FIN-01', 'document_series' => '1',
            'pending' => true, 'confirmed' => false, 'created_by' => $user->id,
        ]);
        $product = Product::create([
            'company_id' => $company->id, 'product_code' => 'SRV-01', 'name' => 'Serviço de terceiros',
            'unit' => Unit::UN->value, 'has_stock_control' => false,
            'origin_sale_price' => OriginSalePrice::FREE->value, 'sale_price_value' => 100,
            'is_active' => true, 'created_by' => $user->id,
        ]);
        FiscalDocumentItem::create([
            'fiscal_document_id' => $document->id, 'product_id' => $product->id, 'description' => 'Serviço de terceiros',
            'item_number' => 1, 'quantity' => 1, 'unit_of_measure' => 'UN',
            'unit_price' => 100, 'total_price' => 100, 'included_in_total' => true, 'created_by' => $user->id,
        ]);
        $category = FinancialCategory::create([
            'company_id' => $company->id, 'name' => 'Fornecedores', 'allow_payable' => true,
            'is_active' => true, 'created_by' => $user->id,
        ]);
        $costCenter = CostCenter::create(['company_id' => $company->id, 'name' => 'Compras', 'is_active' => true]);
        $resultCenter = ResultCenter::create(['company_id' => $company->id, 'name' => 'Operação', 'is_active' => true]);
        $account = FinancialAccount::create([
            'company_id' => $company->id, 'name' => 'Banco', 'type' => FinancialAccountType::BANK->value,
            'opening_balance' => 0, 'is_active' => true, 'created_by' => $user->id,
        ]);
        $user->companies()->attach($company, ['role' => 'admin', 'is_active' => true]);
        $this->actingAs($user);
        Filament::setCurrentPanel('admin');
        Filament::setTenant($company);

        Livewire::test(EditFiscalDocument::class, ['record' => (string) $document->getRouteKey()])
            ->callAction('confirmEntry', data: [
                'generate_account_payable_now' => true, 'payment_method' => Method::PIX->value,
                'installment_count' => $count, 'installment_due_mode' => $mode,
                'installment_fixed_day' => $mode === InstallmentSchedule::FIXED_DAY_OF_MONTH ? $interval : null,
                'installment_interval_days' => $mode === InstallmentSchedule::CUSTOM_INTERVAL_DAYS ? $interval : null,
                'due_date' => '2027-01-31', 'financial_category_id' => $category->id,
                'cost_center_id' => $costCenter->id, 'result_center_id' => $resultCenter->id,
                'bank_slip_number' => 'BOLETO-01', 'is_effective' => $effective,
                'auto_register_payment_on_due_date' => $effective,
                'auto_payment_financial_account_id' => $effective ? $account->id : null,
            ])
            ->assertHasNoActionErrors();

        $this->assertTrue($document->fresh()->confirmed);
        $payable = $document->accountPayables()->sole();
        $this->assertSame($supplier->id, $payable->supplier_id);
        $this->assertSame($company->id, $payable->company_id);
        $this->assertEquals(100, $payable->due_amount);
        $this->assertSame($effective, $payable->is_effective);
        $this->assertSame($effective, $payable->auto_register_payment_on_due_date);
        $this->assertEquals($effective ? $account->id : null, $payable->auto_payment_financial_account_id);
        $this->assertSame('NF-FIN-01', $payable->note_number);
        $this->assertSame('BOLETO-01', $payable->bank_slip_number);
        $installments = $payable->installments()->orderBy('sequence_number')->get();
        $this->assertSame($dates, $installments->map(fn ($item) => $item->due_date->toDateString())->all());
        $this->assertSame($amounts, $installments->map(fn ($item) => (float) $item->due_amount)->all());
        foreach ($installments as $installment) {
            $this->assertSame($category->id, $installment->financial_category_id);
            $this->assertSame($costCenter->id, $installment->cost_center_id);
            $this->assertSame($resultCenter->id, $installment->result_center_id);
            $this->assertSame('NF #NF-FIN-01', $installment->description);
        }
        $this->assertDatabaseCount('account_payable_installment_payments', 0);
    }

    public function test_confirm_entry_rolls_back_everything_when_any_item_fails(): void
    {
        $user = User::factory()->create();

        $company = Company::query()->create([
            'name' => 'Empresa Confirmacao Entrada',
            'document_number' => '12345678000191',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'email' => 'empresa-confirmacao@example.com',
            'is_active' => true,
            'created_by' => $user->id,
        ]);

        $supplier = Partner::query()->create([
            'name' => 'Fornecedor Confirmacao',
            'document_type' => 'CNPJ',
            'document_number' => '22345678000181',
            'created_by' => $user->id,
        ]);

        $validProduct = Product::query()->create([
            'company_id' => $company->id,
            'product_code' => 'PRD-OK',
            'name' => 'Produto com estoque',
            'unit' => Unit::UN->value,
            'has_stock_control' => true,
            'origin_sale_price' => OriginSalePrice::FREE->value,
            'sale_price_value' => 10,
            'is_active' => true,
            'created_by' => $user->id,
        ]);

        $invalidProduct = Product::query()->create([
            'company_id' => $company->id,
            'product_code' => 'PRD-ERRO',
            'name' => 'Produto sem estoque',
            'unit' => Unit::UN->value,
            'has_stock_control' => true,
            'origin_sale_price' => OriginSalePrice::FREE->value,
            'sale_price_value' => 20,
            'is_active' => true,
            'created_by' => $user->id,
        ]);

        ProductStock::query()->create([
            'product_id' => $validProduct->id,
            'company_id' => $company->id,
            'quantity_total' => 0,
            'quantity_reserved' => 0,
            'is_active' => true,
            'allow_negative' => false,
            'created_by' => $user->id,
        ]);

        $document = FiscalDocument::query()->create([
            'customer_id' => $supplier->id,
            'company_id' => $company->id,
            'status' => Status::PENDING->value,
            'issued_at' => now()->toDateString(),
            'movement_at' => now()->toDateString(),
            'document_type' => DocumentModel::NFE->value,
            'operation_type' => OperationType::ENTRADA->value,
            'document_number' => 'NF-ENT-ROLLBACK',
            'document_series' => '1',
            'pending' => true,
            'confirmed' => false,
            'canceled' => false,
            'created_by' => $user->id,
        ]);

        FiscalDocumentItem::query()->create([
            'fiscal_document_id' => $document->id,
            'product_id' => $validProduct->id,
            'product_code' => $validProduct->product_code,
            'description' => $validProduct->name,
            'item_number' => 1,
            'quantity' => 3,
            'unit_of_measure' => Unit::UN->value,
            'unit_price' => 10,
            'total_price' => 30,
            'created_by' => $user->id,
        ]);

        FiscalDocumentItem::query()->create([
            'fiscal_document_id' => $document->id,
            'product_id' => $invalidProduct->id,
            'product_code' => $invalidProduct->product_code,
            'description' => $invalidProduct->name,
            'item_number' => 2,
            'quantity' => 1,
            'unit_of_measure' => Unit::UN->value,
            'unit_price' => 20,
            'total_price' => 20,
            'created_by' => $user->id,
        ]);

        $user->companies()->attach($company, [
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->actingAs($user);
        Filament::setCurrentPanel('admin');
        Filament::setTenant($company);

        Livewire::test(EditFiscalDocument::class, ['record' => (string) $document->getRouteKey()])
            ->assertActionExists('confirmEntry')
            ->assertActionVisible('confirmEntry')
            ->callAction('confirmEntry');

        $document->refresh();

        $this->assertFalse($document->confirmed);
        $this->assertTrue($document->pending);
        $this->assertSame(Status::PENDING, $document->status);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertSame(0.0, (float) ProductStock::query()->where('product_id', $validProduct->id)->value('quantity_total'));
        $this->assertNull(StockMovement::query()->first());
    }
}
