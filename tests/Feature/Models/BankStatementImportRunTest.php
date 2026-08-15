<?php

namespace Tests\Feature\Models;

use App\Enum\Financial\BankStatementImportStatus;
use App\Enum\Financial\BankStatementLineStatus;
use App\Enum\Financial\FinancialAccountType;
use App\Models\BankStatementImport;
use App\Models\BankStatementImportRun;
use App\Models\BankStatementLine;
use App\Models\Company;
use App\Models\FinancialAccount;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BankStatementImportRunTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_tracks_the_import_run_that_last_saw_a_statement_line(): void
    {
        $user = User::factory()->create();
        $company = Company::create([
            'name' => 'Empresa Extrato',
            'document_number' => '12345678000111',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'created_by' => $user->id,
        ]);
        $account = FinancialAccount::create([
            'company_id' => $company->id,
            'name' => 'Conta Corrente',
            'type' => FinancialAccountType::BANK->value,
            'is_active' => true,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
        $import = BankStatementImport::create([
            'company_id' => $company->id,
            'financial_account_id' => $account->id,
            'source' => 'ofx',
            'status' => BankStatementImportStatus::PENDING->value,
            'created_by' => $user->id,
        ]);
        $run = BankStatementImportRun::create([
            'bank_statement_import_id' => $import->id,
            'file_hash' => str_repeat('a', 64),
            'file_name' => 'extrato.ofx',
            'status' => BankStatementImportStatus::COMPLETED->value,
            'summary' => ['created' => 1, 'preserved' => 0],
            'started_at' => now()->subSecond(),
            'completed_at' => now(),
            'created_by' => $user->id,
        ]);
        $line = BankStatementLine::create([
            'bank_statement_import_id' => $import->id,
            'company_id' => $company->id,
            'financial_account_id' => $account->id,
            'transaction_date' => '2026-08-15',
            'amount' => 100,
            'description' => 'Recebimento',
            'external_id' => 'FITID-1',
            'transaction_key' => 'fitid:FITID-1',
            'reconciliation_status' => BankStatementLineStatus::NEEDS_REVIEW->value,
            'last_seen_import_run_id' => $run->id,
            'source_payload_hash' => str_repeat('b', 64),
            'needs_review_at' => now(),
            'review_reason' => 'Valor divergente na reimportação.',
        ]);

        $this->assertTrue($line->lastSeenImportRun->is($run));
        $this->assertTrue($run->import->is($import));
        $this->assertTrue($run->lastSeenLines->first()->is($line));
        $this->assertSame(BankStatementImportStatus::COMPLETED, $run->status);
        $this->assertSame(['created' => 1, 'preserved' => 0], $run->summary);
        $this->assertSame(BankStatementLineStatus::NEEDS_REVIEW, $line->reconciliation_status);
        $this->assertNotNull($line->needs_review_at);

        $this->expectException(QueryException::class);

        BankStatementLine::create([
            'bank_statement_import_id' => $import->id,
            'company_id' => $company->id,
            'financial_account_id' => $account->id,
            'transaction_date' => '2026-08-15',
            'amount' => 100,
            'description' => 'Recebimento duplicado',
            'transaction_key' => 'fitid:FITID-1',
            'reconciliation_status' => BankStatementLineStatus::PENDING->value,
        ]);
    }
}
