<?php

namespace Tests\Feature\Services\FiscalDocument;

use App\Enum\FiscalDocument\DocumentModel;
use App\Enum\FiscalDocument\Status as FiscalDocumentStatus;
use App\Enum\Payment\Condition;
use App\Enum\Payment\Method;
use App\Models\Company;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentItem;
use App\Models\Partner;
use App\Models\User;
use App\Services\FiscalDocument\Actions\ProcessFiscalEntryAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProcessFiscalEntryActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_process_fiscal_entry_generates_account_payable_without_breaking_current_flow(): void
    {
        $user = User::factory()->create();

        $company = Company::create([
            'name' => 'Empresa Entrada Fiscal',
            'document_number' => '12345678000177',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'created_by' => $user->id,
        ]);

        $supplier = Partner::create([
            'name' => 'Fornecedor Fiscal',
            'document_type' => 'CNPJ',
            'document_number' => '22345678000155',
            'created_by' => $user->id,
        ]);

        $document = FiscalDocument::create([
            'customer_id' => $supplier->id,
            'company_id' => $company->id,
            'status' => FiscalDocumentStatus::CONFIRMED->value,
            'issued_at' => '2026-04-08',
            'movement_at' => '2026-04-08',
            'document_type' => DocumentModel::NFE->value,
            'document_number' => 'NF-ENT-100',
            'document_series' => '1',
            'created_by' => $user->id,
        ]);

        FiscalDocumentItem::create([
            'fiscal_document_id' => $document->id,
            'product_id' => null,
            'product_code' => 'SEM-ESTOQUE',
            'description' => 'Servico de terceiros',
            'item_number' => 1,
            'product_origin' => null,
            'ncm_code' => null,
            'cfop_code' => null,
            'quantity' => 1,
            'unit_of_measure' => 'UN',
            'unit_price' => 100,
            'total_price' => 100,
            'included_in_total' => true,
            'created_by' => $user->id,
        ]);

        $result = app(ProcessFiscalEntryAction::class)->execute($document, [
            'payment_method' => Method::PIX->value,
            'payment_condition' => Condition::CASH->value,
            'due_date' => '2026-04-08',
            'description' => 'NF de entrada teste',
        ], $user->id);

        $resultJson = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->assertSame(1, $result['payables'], $resultJson ?: 'Sem retorno serializavel.');
        $this->assertSame(0, $result['stock_movements']);
        $this->assertCount(0, $result['errors']);
        $this->assertDatabaseCount('account_payables', 1);
        $this->assertDatabaseCount('account_payable_installments', 1);
    }
}
