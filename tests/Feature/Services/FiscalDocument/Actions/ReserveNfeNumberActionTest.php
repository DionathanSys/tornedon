<?php

namespace Tests\Feature\Services\FiscalDocument\Actions;

use App\Enum\FiscalDocument\DocumentModel;
use App\Enum\FiscalDocument\OperationNature;
use App\Enum\FiscalDocument\OperationType;
use App\Enum\FiscalDocument\Status;
use App\Models\Company;
use App\Models\FiscalDocument;
use App\Models\NfeSequence;
use App\Models\Partner;
use App\Models\User;
use App\Services\FiscalDocument\Actions\ReserveNfeNumberAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReserveNfeNumberActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_blocks_reservation_when_sequence_diverges_from_fiscal_documents(): void
    {
        $user = User::factory()->create();

        $company = Company::query()->create([
            'name' => 'Empresa Reserva',
            'document_number' => '12345678000199',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'created_by' => $user->id,
        ]);

        $customer = Partner::query()->create([
            'name' => 'Cliente Reserva',
            'document_type' => 'CNPJ',
            'document_number' => '22345678000188',
            'created_by' => $user->id,
        ]);

        NfeSequence::query()->create([
            'company_id' => $company->id,
            'serie' => '1',
            'operation_nature' => OperationNature::DEVOLUCAO_COMPRA->value,
            'last_number' => 6,
        ]);

        FiscalDocument::query()->create([
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'status' => Status::PENDING->value,
            'document_type' => DocumentModel::NFE->value,
            'issued_at' => now()->toDateString(),
            'movement_at' => now()->toDateString(),
            'document_number' => '7',
            'document_series' => '1',
            'operation_nature' => OperationNature::VENDA_DENTRO_ESTADO->value,
            'operation_type' => OperationType::SAIDA->value,
            'created_by' => $user->id,
        ]);

        $documentToReserve = FiscalDocument::query()->create([
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'status' => Status::PENDING->value,
            'document_type' => DocumentModel::NFE->value,
            'issued_at' => now()->toDateString(),
            'movement_at' => now()->toDateString(),
            'document_number' => null,
            'document_series' => null,
            'operation_nature' => OperationNature::DEVOLUCAO_COMPRA->value,
            'operation_type' => OperationType::SAIDA->value,
            'created_by' => $user->id,
        ]);

        $action = new ReserveNfeNumberAction();

        $this->assertFalse($action->execute($documentToReserve, '1', OperationNature::DEVOLUCAO_COMPRA->value));

        $documentToReserve->refresh();

        $this->assertNull($documentToReserve->document_number);
        $this->assertNull($documentToReserve->document_series);
        $this->assertNull($documentToReserve->nfe_sequence_id);
        $this->assertSame(6, NfeSequence::query()->where('company_id', $company->id)->where('serie', '1')->value('last_number'));
        $this->assertStringContainsString('Divergência na sequência NF-e', $action->getMessage());
    }

    public function test_it_reserves_number_when_sequence_is_synchronized(): void
    {
        $user = User::factory()->create();

        $company = Company::query()->create([
            'name' => 'Empresa Peek',
            'document_number' => '12345678000199',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'created_by' => $user->id,
        ]);

        $customer = Partner::query()->create([
            'name' => 'Cliente Peek',
            'document_type' => 'CNPJ',
            'document_number' => '22345678000188',
            'created_by' => $user->id,
        ]);

        NfeSequence::query()->create([
            'company_id' => $company->id,
            'serie' => '1',
            'operation_nature' => OperationNature::DEVOLUCAO_COMPRA->value,
            'last_number' => 7,
        ]);

        FiscalDocument::query()->create([
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'status' => Status::PENDING->value,
            'document_type' => DocumentModel::NFE->value,
            'issued_at' => now()->toDateString(),
            'movement_at' => now()->toDateString(),
            'document_number' => '7',
            'document_series' => '1',
            'operation_nature' => OperationNature::VENDA_DENTRO_ESTADO->value,
            'operation_type' => OperationType::SAIDA->value,
            'created_by' => $user->id,
        ]);

        $documentToReserve = FiscalDocument::query()->create([
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'status' => Status::PENDING->value,
            'document_type' => DocumentModel::NFE->value,
            'issued_at' => now()->toDateString(),
            'movement_at' => now()->toDateString(),
            'document_number' => null,
            'document_series' => null,
            'operation_nature' => OperationNature::DEVOLUCAO_COMPRA->value,
            'operation_type' => OperationType::SAIDA->value,
            'created_by' => $user->id,
        ]);

        $action = new ReserveNfeNumberAction();

        $this->assertTrue($action->execute($documentToReserve, '1', OperationNature::DEVOLUCAO_COMPRA->value));

        $documentToReserve->refresh();

        $this->assertSame('8', $documentToReserve->document_number);
        $this->assertSame('1', $documentToReserve->document_series);
        $this->assertSame(8, NfeSequence::query()->whereKey($documentToReserve->nfe_sequence_id)->value('last_number'));
    }

    public function test_it_blocks_peek_when_sequence_diverges_from_fiscal_documents(): void
    {
        $user = User::factory()->create();

        $company = Company::query()->create([
            'name' => 'Empresa Entrada',
            'document_number' => '12345678000199',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'created_by' => $user->id,
        ]);

        $supplier = Partner::query()->create([
            'name' => 'Fornecedor Entrada',
            'document_type' => 'CNPJ',
            'document_number' => '22345678000188',
            'created_by' => $user->id,
        ]);

        NfeSequence::query()->create([
            'company_id' => $company->id,
            'serie' => '1',
            'operation_nature' => OperationNature::VENDA_DENTRO_ESTADO->value,
            'last_number' => 4,
        ]);

        FiscalDocument::query()->create([
            'customer_id' => $supplier->id,
            'company_id' => $company->id,
            'status' => Status::PENDING->value,
            'document_type' => DocumentModel::NFE->value,
            'issued_at' => now()->toDateString(),
            'movement_at' => now()->toDateString(),
            'document_number' => '9999',
            'document_series' => '1',
            'operation_nature' => OperationNature::VENDA_DENTRO_ESTADO->value,
            'operation_type' => OperationType::SAIDA->value,
            'created_by' => $user->id,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Divergência na sequência NF-e');

        NfeSequence::peekNextNumber(
            $company->id,
            '1',
            OperationNature::VENDA_DENTRO_ESTADO->value
        );
    }
}
