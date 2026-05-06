<?php

namespace Tests\Feature\Services\FiscalDocument\Actions;

use App\Enum\FiscalDocument\DocumentModel;
use App\Enum\FiscalDocument\NfeStatus;
use App\Enum\FiscalDocument\OperationNature;
use App\Enum\FiscalDocument\OperationType;
use App\Enum\FiscalDocument\Status;
use App\Models\Company;
use App\Models\FiscalDocument;
use App\Models\NfeSequence;
use App\Models\Partner;
use App\Models\User;
use App\Services\FiscalDocument\Actions\ResolveRejectedNfeNumberAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResolveRejectedNfeNumberActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_keeps_same_number_when_rejected_document_is_still_sequence_tail(): void
    {
        [$user, $company, $customer] = $this->createContext();

        $document = $this->createDocument($company, $customer, $user, [
            'document_number' => '10',
            'document_series' => '1',
            'nfe_status' => NfeStatus::REJECTED->value,
        ]);

        NfeSequence::query()->create([
            'company_id' => $company->id,
            'serie' => '1',
            'operation_nature' => OperationNature::VENDA_DENTRO_ESTADO->value,
            'last_number' => 10,
        ]);

        $action = new ResolveRejectedNfeNumberAction();

        $this->assertTrue($action->execute($document, '1'));

        $document->refresh();

        $this->assertSame('10', $document->document_number);
        $this->assertSame('1', $document->document_series);
        $this->assertSame(10, NfeSequence::query()->where('company_id', $company->id)->where('serie', '1')->value('last_number'));
    }

    public function test_it_assigns_new_number_when_rejected_document_is_behind_sequence_tail(): void
    {
        [$user, $company, $customer] = $this->createContext();

        $document = $this->createDocument($company, $customer, $user, [
            'document_number' => '10',
            'document_series' => '1',
            'nfe_status' => NfeStatus::REJECTED->value,
        ]);

        $this->createDocument($company, $customer, $user, [
            'document_number' => '11',
            'document_series' => '1',
            'nfe_status' => NfeStatus::PENDING->value,
        ]);

        NfeSequence::query()->create([
            'company_id' => $company->id,
            'serie' => '1',
            'operation_nature' => OperationNature::VENDA_DENTRO_ESTADO->value,
            'last_number' => 11,
        ]);

        $action = new ResolveRejectedNfeNumberAction();

        $this->assertTrue($action->execute($document, '1'));

        $document->refresh();

        $this->assertSame('12', $document->document_number);
        $this->assertSame('1', $document->document_series);
        $this->assertNull($document->nfe_sequence_id);
        $this->assertSame(11, NfeSequence::query()->where('company_id', $company->id)->where('serie', '1')->value('last_number'));
    }

    private function createContext(): array
    {
        $user = User::factory()->create();

        $company = Company::query()->create([
            'name' => 'Empresa Rejeitada',
            'document_number' => '12345678000199',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'created_by' => $user->id,
        ]);

        $customer = Partner::query()->create([
            'name' => 'Cliente Rejeitada',
            'document_type' => 'CNPJ',
            'document_number' => '22345678000188',
            'created_by' => $user->id,
        ]);

        return [$user, $company, $customer];
    }

    private function createDocument(Company $company, Partner $customer, User $user, array $overrides = []): FiscalDocument
    {
        return FiscalDocument::query()->create(array_merge([
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'status' => Status::PENDING->value,
            'document_type' => DocumentModel::NFE->value,
            'issued_at' => now()->toDateString(),
            'movement_at' => now()->toDateString(),
            'operation_nature' => OperationNature::VENDA_DENTRO_ESTADO->value,
            'operation_type' => OperationType::SAIDA->value,
            'nfe_status' => NfeStatus::PENDING->value,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ], $overrides));
    }
}
