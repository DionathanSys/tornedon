<?php

namespace Tests\Feature\Services\CompanyCard;

use App\Models\Company;
use App\Models\CompanyCardStatementPayment;
use App\Models\CompanyCardTransaction;
use App\Models\CompanyCreditCard;
use App\Models\FinancialAccount;
use App\Models\Partner;
use App\Models\User;
use App\Services\CompanyCard\CompanyCardStatementPaymentService;
use App\Services\CompanyCard\CompanyCardStatementService;
use App\Services\CompanyCard\CompanyCardTransactionService;
use App\Enum\Financial\FinancialAccountType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyCardFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Company $company;
    private Partner $issuerPartner;
    private Partner $vendor;
    private FinancialAccount $defaultAccount;
    private FinancialAccount $secondaryAccount;
    private CompanyCreditCard $card;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->company = Company::create([
            'name' => 'Empresa Cartão',
            'document_number' => '10101010000110',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'created_by' => $this->user->id,
        ]);

        $this->issuerPartner = Partner::create([
            'name' => 'Banco Emissor',
            'document_type' => 'CNPJ',
            'document_number' => '12345678000199',
            'created_by' => $this->user->id,
        ]);

        $this->vendor = Partner::create([
            'name' => 'Fornecedor A',
            'document_type' => 'CNPJ',
            'document_number' => '99887766000155',
            'created_by' => $this->user->id,
        ]);

        $this->defaultAccount = FinancialAccount::create([
            'company_id' => $this->company->id,
            'name' => 'Conta Principal',
            'type' => FinancialAccountType::BANK->value,
            'opening_balance' => 10000,
            'is_active' => true,
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
        ]);

        $this->secondaryAccount = FinancialAccount::create([
            'company_id' => $this->company->id,
            'name' => 'Conta Alternativa',
            'type' => FinancialAccountType::BANK->value,
            'opening_balance' => 5000,
            'is_active' => true,
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
        ]);

        $this->card = CompanyCreditCard::create([
            'company_id' => $this->company->id,
            'name' => 'Cartão Corporativo 01',
            'issuer' => 'Banco Emissor',
            'issuer_partner_id' => $this->issuerPartner->id,
            'last_four' => '1234',
            'credit_limit' => 25000,
            'closing_day' => 25,
            'due_day' => 5,
            'statement_cutoff_business_days' => 2,
            'default_financial_account_id' => $this->defaultAccount->id,
            'active' => true,
        ]);
    }

    public function test_resolves_cutoff_and_reference_month_with_business_days(): void
    {
        $service = app(CompanyCardStatementService::class);

        $cutoff = $service->resolveCutoffDate($this->card, '2026-05-01');
        $referenceOnCutoff = $service->resolveReferenceMonth($this->card, '2026-05-21');
        $referenceAfterCutoff = $service->resolveReferenceMonth($this->card, '2026-05-22');

        $this->assertSame('2026-05-21', $cutoff->toDateString());
        $this->assertSame('2026-05-01', $referenceOnCutoff->toDateString());
        $this->assertSame('2026-06-01', $referenceAfterCutoff->toDateString());
    }

    public function test_manual_transaction_creates_one_line_per_installment(): void
    {
        $service = app(CompanyCardTransactionService::class);

        $created = $service->createManual([
            'company_id' => $this->company->id,
            'company_credit_card_id' => $this->card->id,
            'transaction_date' => '2026-05-10',
            'description' => 'Compra parcelada',
            'vendor_id' => $this->vendor->id,
            'amount' => 900,
            'installments' => 3,
        ], $this->user->id);

        $this->assertNotNull($created, $service->getMessage());
        $this->assertCount(3, $created);

        $rows = CompanyCardTransaction::query()
            ->where('company_id', $this->company->id)
            ->where('company_credit_card_id', $this->card->id)
            ->orderBy('current_installment')
            ->get();

        $this->assertCount(3, $rows);
        $this->assertSame([1, 2, 3], $rows->pluck('current_installment')->all());
        $this->assertSame([300.0, 300.0, 300.0], $rows->pluck('amount')->map(fn ($v) => (float) $v)->all());
        $this->assertTrue($rows->pluck('installment_group_uuid')->filter()->count() === 3);
    }

    public function test_statement_close_generates_payable_and_payment_allows_overriding_default_account(): void
    {
        $transactionService = app(CompanyCardTransactionService::class);
        $statementService = app(CompanyCardStatementService::class);
        $paymentService = app(CompanyCardStatementPaymentService::class);

        $created = $transactionService->createManual([
            'company_id' => $this->company->id,
            'company_credit_card_id' => $this->card->id,
            'transaction_date' => '2026-05-15',
            'description' => 'Compra avulsa',
            'vendor_id' => $this->vendor->id,
            'amount' => 1200,
            'installments' => 1,
        ], $this->user->id);

        $this->assertNotNull($created, $transactionService->getMessage());

        $statement = $statementService->generateStatement($this->card, '2026-05-01');
        $this->assertNotNull($statement, $statementService->getMessage());
        $this->assertSame(1200.0, (float) $statement->gross_total);

        $closed = $statementService->closeStatement($statement->fresh(), $this->user->id);
        $this->assertNotNull($closed, $statementService->getMessage());
        $this->assertNotNull($closed->account_payable_id);
        $this->assertSame('closed', $closed->status->value);
        $this->assertSame($this->defaultAccount->id, (int) $closed->accountPayable->auto_payment_financial_account_id);

        $payment = $paymentService->registerPayment($closed->fresh(), 200, '2026-06-05', [
            'financial_account_id' => $this->secondaryAccount->id,
            'user_id' => $this->user->id,
            'notes' => 'Baixa parcial em conta alternativa',
        ]);

        $this->assertNotNull($payment, $paymentService->getMessage());
        $this->assertSame($this->secondaryAccount->id, (int) $payment->financial_account_id);

        $statementPayment = CompanyCardStatementPayment::query()->find($payment->id);
        $this->assertNotNull($statementPayment);
        $this->assertSame($this->secondaryAccount->id, (int) $statementPayment->financial_account_id);

        $updatedStatement = $closed->fresh();
        $this->assertSame(200.0, (float) $updatedStatement->paid_total);
        $this->assertSame(1000.0, (float) $updatedStatement->balance_total);
        $this->assertSame('partial', $updatedStatement->status->value);
    }
}
