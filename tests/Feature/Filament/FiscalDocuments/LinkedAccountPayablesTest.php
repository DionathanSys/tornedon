<?php

namespace Tests\Feature\Filament\FiscalDocuments;

use App\Enum\FiscalDocument\DocumentModel;
use App\Enum\FiscalDocument\OperationType;
use App\Enum\FiscalDocument\Status;
use App\Filament\Clusters\Financial\Resources\FiscalDocuments\Pages\EditFiscalDocument;
use App\Models\AccountPayable;
use App\Models\Company;
use App\Models\FiscalDocument;
use App\Models\Partner;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class LinkedAccountPayablesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $compiledPath = '/tmp/opencode/tornedon-views-test-'.Str::uuid();
        File::ensureDirectoryExists($compiledPath);
        config(['view.compiled' => $compiledPath]);

        $cachePath = new \ReflectionProperty(app('blade.compiler'), 'cachePath');
        $cachePath->setValue(app('blade.compiler'), $compiledPath);
    }

    public function test_edit_page_displays_linked_accounts_and_installments_without_other_company_data(): void
    {
        [$company, $document, $supplier, $user] = $this->createScenario();

        $account = $this->createAccount($company, $document, $supplier, $user, 'Compra de materiais vinculada');
        $account->installments()->create([
            'company_id' => $company->id,
            'sequence_number' => '01',
            'status' => 'partially_paid',
            'due_date' => '2026-12-20',
            'original_amount' => 123.45,
            'due_amount' => 123.45,
            'paid_amount' => 23.45,
            'balance_amount' => 100,
        ]);

        $otherCompany = Company::query()->create([
            'name' => 'Outra empresa',
            'document_number' => '98765432000198',
            'address' => ['city' => 'São Paulo', 'state' => 'SP'],
            'email' => 'outra@example.com',
            'created_by' => $user->id,
        ]);
        $this->createAccount($otherCompany, $document, $supplier, $user, 'Conta de outra empresa');
        $this->createAccount($company, $document, $supplier, $user, 'Outra conta da mesma nota');

        Livewire::test(EditFiscalDocument::class, ['record' => (string) $document->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('Contas a pagar da nota')
            ->assertSee('Compra de materiais vinculada')
            ->assertSee('Outra conta da mesma nota')
            ->assertSee('20/12/2026')
            ->assertSee('Parcialmente Pago')
            ->assertSee('123,45')
            ->assertSee('23,45')
            ->assertSee('100,00')
            ->assertDontSee('Conta de outra empresa');
    }

    public function test_edit_page_shows_empty_state_when_no_payable_is_linked(): void
    {
        [, $document] = $this->createScenario();

        Livewire::test(EditFiscalDocument::class, ['record' => (string) $document->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('Nenhuma conta a pagar vinculada a esta nota.');
    }

    private function createScenario(): array
    {
        /** @var User $user */
        $user = User::factory()->create();
        $company = Company::query()->create([
            'name' => 'Empresa da nota',
            'document_number' => '12345678000191',
            'address' => ['city' => 'São Paulo', 'state' => 'SP'],
            'email' => 'nota@example.com',
            'created_by' => $user->id,
        ]);
        $user->companies()->attach($company, ['role' => 'admin', 'is_active' => true]);
        $supplier = Partner::query()->create([
            'name' => 'Fornecedor da nota',
            'document_type' => 'CNPJ',
            'document_number' => '22345678000181',
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
            'document_number' => '123',
            'created_by' => $user->id,
        ]);

        $this->actingAs($user);
        Filament::setCurrentPanel('admin');
        Filament::setTenant($company);

        return [$company, $document, $supplier, $user];
    }

    private function createAccount(Company $company, FiscalDocument $document, Partner $supplier, User $user, string $description): AccountPayable
    {
        return AccountPayable::query()->create([
            'company_id' => $company->id,
            'supplier_id' => $supplier->id,
            'fiscal_document_id' => $document->id,
            'sequence_number' => '01',
            'created_by' => $user->id,
            'status' => 'pending',
            'due_date' => '2026-12-20',
            'due_amount' => 123.45,
            'paid_amount' => 23.45,
            'payment_method' => 'boleto',
            'description' => $description,
        ]);
    }
}
