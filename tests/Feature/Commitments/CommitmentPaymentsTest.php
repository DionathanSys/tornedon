<?php

namespace Tests\Feature\Commitments;

use App\Enum\Commitment\ImportType;
use App\Enum\Commitment\PaymentLineStatus;
use App\Enum\Commitment\ReceiptStatus;
use App\Enum\Payment\Condition;
use App\Jobs\MatchCommitmentPaymentLinesJob;
use App\Jobs\ProcessCommitmentImportFileJob;
use App\Models\AccountReceivableInstallmentPayment;
use App\Models\Company;
use App\Models\FinancialAccount;
use App\Models\User;
use App\Services\AccountReceivable\AccountReceivableService;
use App\Services\Commitments\DTO\ExtractedPdf;
use App\Services\Commitments\ImportCommitmentFilesService;
use App\Services\Commitments\MatchCommitmentPaymentsService;
use App\Services\Commitments\Pdf\PdfTextExtractor;
use App\Services\Commitments\ProcessCommitmentImportFileService;
use App\Services\Commitments\ReceiveCommitmentPaymentService;
use App\Services\Commitments\ReviewCommitmentPaymentsService;
use App\Services\Financial\CardReceivableCalculatorService;
use App\Services\Financial\CashMovementService;
use App\Services\Financial\FinancialClassificationService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\CreatesCommitmentPaymentFixtures;
use Tests\TestCase;

class CommitmentPaymentsTest extends TestCase
{
    use CreatesCommitmentPaymentFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createPaymentContext();
    }

    private function refreshLine($line)
    {
        return app(MatchCommitmentPaymentsService::class)->refresh($this->company->id, $line->id, $this->user->id);
    }

    private function receiveLine($line, bool $linkExisting = false)
    {
        return app(ReceiveCommitmentPaymentService::class)->receive($this->company->id, $line->id, $this->user->id, $this->cashAccount->id, '2026-08-01', $linkExisting);
    }

    public function test_matches_and_receives_full_balance_using_report_date_existing_financial_service_and_evidence(): void
    {
        $billing = $this->billedCommitment();
        $line = $this->paymentLine($billing);
        $this->assertSame(PaymentLineStatus::MATCHED, $this->refreshLine($line)->status);
        $receipt = $this->receiveLine($line);
        $this->assertSame(ReceiptStatus::RECEIVED, $receipt->status);
        $this->assertSame(PaymentLineStatus::RECEIVED, $line->fresh()->status);
        $payment = AccountReceivableInstallmentPayment::sole();
        $this->assertSame('commitment_report', $payment->origin);
        $this->assertSame('2026-08-27', $payment->payment_date->toDateString());
        $this->assertSame('2026-08-01', $payment->installment->competence_date->toDateString());
        $this->assertSame(0, (int) $payment->installment->getRawOriginal('balance_amount'));
        $this->assertSame($payment->id, $receipt->paymentLinks()->sole()->payment_id);
        $this->assertDatabaseCount('cash_movements', 1);
        $this->assertSame(40000, (int) $receipt->getRawOriginal('amount'));
    }

    public function test_multiple_installments_settle_atomically_and_second_failure_rolls_everything_back(): void
    {
        $billing = $this->billedCommitment(Condition::DAYS_30_60);
        $line = $this->paymentLine($billing);
        $service = \Mockery::mock(AccountReceivableService::class, [app(FinancialClassificationService::class), app(CashMovementService::class), app(CardReceivableCalculatorService::class)])->makePartial();
        $this->app->instance(AccountReceivableService::class, $service);
        $service->shouldReceive('registerInstallmentPayment')->once()->passthru()->ordered();
        $service->shouldReceive('registerInstallmentPayment')->once()->andReturn(null)->ordered();
        $service->shouldReceive('getMessageUser')->andReturn('Falha na segunda parcela.');
        try {
            $this->receiveLine($line);
            $this->fail('Partial settlement committed.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('account_receivable_installment_payments', 0);
            $this->assertDatabaseCount('cash_movements', 0);
            $this->assertDatabaseCount('commitment_receipts', 0);
            $this->assertSame(40000, (int) $billing->invoice->accountReceivables()->sole()->installments()->sum('balance_amount'));
        }
        $this->app->forgetInstance(AccountReceivableService::class);
        $receipt = $this->receiveLine($line);
        $this->assertSame(2, $receipt->paymentLinks()->count());
        $this->assertDatabaseCount('cash_movements', 2);
    }

    public function test_overlap_and_repeated_confirmation_reuse_existing_settlement(): void
    {
        $billing = $this->billedCommitment();
        $line = $this->paymentLine($billing);
        $receipt = $this->receiveLine($line);
        $duplicate = $this->paymentLine($billing);
        $this->assertSame(PaymentLineStatus::ALREADY_RECEIVED, $this->refreshLine($duplicate)->status);
        $this->assertSame($receipt->id, $this->receiveLine($duplicate)->id);
        $this->assertSame($receipt->id, $this->receiveLine($line)->id);
        $different = $this->paymentLine($billing, ['amount' => 390]);
        $this->assertSame(PaymentLineStatus::DIVERGENT, $this->refreshLine($different)->status);
        $this->assertNull($different->fresh()->commitment_receipt_id);
        $this->assertDatabaseCount('commitment_receipts', 1);
        $this->assertDatabaseCount('account_receivable_installment_payments', 1);
        $this->assertDatabaseCount('cash_movements', 1);
    }

    public function test_manual_payment_is_not_repeated_and_can_be_explicitly_linked(): void
    {
        $billing = $this->billedCommitment();
        $line = $this->paymentLine($billing);
        $payment = app(AccountReceivableService::class)->registerInstallmentPayment($billing->invoice->accountReceivables()->sole()->installments()->sole(), 400, '2026-08-27', ['financial_account_id' => $this->cashAccount->id]);
        $this->assertNotNull($payment);
        $this->assertSame(PaymentLineStatus::ALREADY_RECEIVED, $this->refreshLine($line)->status);
        try {
            $this->receiveLine($line);
            $this->fail('Existing manual receipt was posted twice.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('account_receivable_installment_payments', 1);
        }
        $receipt = $this->receiveLine($line, true);
        $this->assertSame($payment->id, $receipt->paymentLinks()->sole()->payment_id);
        $this->assertDatabaseCount('cash_movements', 1);
        $this->assertSame('manual', $payment->fresh()->origin);
    }

    public function test_partial_payment_between_suggestion_and_confirmation_blocks_stale_full_receipt(): void
    {
        $billing = $this->billedCommitment();
        $line = $this->paymentLine($billing);
        $this->refreshLine($line);
        app(AccountReceivableService::class)->registerInstallmentPayment($billing->invoice->accountReceivables()->sole()->installments()->sole(), 20, '2026-08-26', ['financial_account_id' => $this->cashAccount->id]);
        try {
            $this->receiveLine($line);
            $this->fail('Stale balance accepted.');
        } catch (ValidationException) {
            $this->assertSame(PaymentLineStatus::DIVERGENT, $line->fresh()->status);
            $this->assertDatabaseCount('account_receivable_installment_payments', 1);
            $this->assertDatabaseCount('commitment_receipts', 0);
        }
    }

    public function test_removed_receipt_requires_review_and_never_recreates_payment(): void
    {
        $billing = $this->billedCommitment();
        $line = $this->paymentLine($billing);
        $receipt = $this->receiveLine($line);
        $this->assertTrue(app(AccountReceivableService::class)->deleteInstallmentPayment($receipt->paymentLinks()->sole()->payment));
        $this->assertSame(PaymentLineStatus::DIVERGENT, $this->refreshLine($line)->status);
        $this->assertSame(ReceiptStatus::NEEDS_REVIEW, $receipt->fresh()->status);
        try {
            $this->receiveLine($line);
            $this->fail('Reversed receipt recreated.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('account_receivable_installment_payments', 0);
            $this->assertDatabaseCount('commitment_receipts', 1);
        }
    }

    public function test_report_integrity_requires_review_but_legacy_creditor_name_warning_is_ignored(): void
    {
        $line = $this->paymentLine($this->billedCommitment());
        $file = $line->importFile;
        $file->update(['extracted_data' => ['issues' => ['Nome do credor não corresponde à empresa selecionada.']]]);
        $this->assertSame(PaymentLineStatus::MATCHED, $this->refreshLine($line)->status);
        $file->update(['extracted_data' => ['issues' => ['A soma dos pagamentos extraídos difere do total declarado do credor.']]]);
        $this->assertSame(PaymentLineStatus::DIVERGENT, $this->refreshLine($line)->status);
        app(ReviewCommitmentPaymentsService::class)->acknowledgeFile($this->company->id, $file->id, $this->user->id, 'Conferi os totais no PDF original.');
        $this->assertSame(PaymentLineStatus::MATCHED, $line->fresh()->status);
        $this->assertDatabaseCount('account_receivable_installment_payments', 0);
    }

    public function test_foreign_tenant_unauthorized_user_and_foreign_cash_account_cannot_receive(): void
    {
        $line = $this->paymentLine($this->billedCommitment());
        $other = Company::factory()->create();
        $foreignAccount = FinancialAccount::create(['company_id' => $other->id, 'name' => 'Outra empresa', 'type' => 'cash', 'opening_balance' => 0, 'is_active' => true]);
        foreach ([[$other->id, $this->user->id, $this->cashAccount->id], [$this->company->id, User::factory()->create()->id, $this->cashAccount->id], [$this->company->id, $this->user->id, $foreignAccount->id]] as [$companyId, $userId, $accountId]) {
            try {
                app(ReceiveCommitmentPaymentService::class)->receive($companyId, $line->id, $userId, $accountId);
                $this->fail('Foreign financial context accepted.');
            } catch (ValidationException|ModelNotFoundException|HttpException) {
                $this->assertDatabaseCount('account_receivable_installment_payments', 0);
            }
        }
    }

    public function test_missing_commitment_wrong_nfse_patrimony_and_amount_do_not_match(): void
    {
        $billing = $this->billedCommitment();
        foreach ([['commitment_number' => '999999'], ['fiscal_document_number' => '98765'], ['patrimony' => '999999'], ['amount' => 380]] as $attributes) {
            $line = $this->paymentLine($billing, $attributes);
            $this->assertNotSame(PaymentLineStatus::MATCHED, $this->refreshLine($line)->status);
        }
        $this->assertDatabaseCount('account_receivable_installment_payments', 0);
    }

    public function test_import_into_different_company_name_automatically_matches_then_receives_selected_report_line(): void
    {
        Storage::fake('local');
        $this->company->update(['name' => 'EMPRESA DE TESTES DIFERENTE']);
        $this->customer->update(['document_number' => '83021808000182']);
        $billing = $this->billedCommitment();
        $number = $billing->invoice->fiscalDocuments()->sole()->document_number;
        $text = strtr(file_get_contents(base_path('tests/Fixtures/Commitments/chapeco-payments.txt')), [
            '14732/2026' => $billing->commitment->number.'/2026', 'PAT. 92181' => 'PAT. 142186', '190,00' => '400,00', '1.670,00' => '1.880,00',
            'Nota Fiscal - 247 - 13/07/2026' => 'Nota Fiscal - '.$number.' - 01/08/2026', "\n---PAGE---\n" => "\f",
        ]);
        $this->mock(PdfTextExtractor::class)->shouldReceive('extract')->once()->andReturn(ExtractedPdf::fromText($text));
        $import = app(ImportCommitmentFilesService::class)->import($this->company->id, $this->customer->id, $this->user->id, ImportType::PAYMENTS,
            [UploadedFile::fake()->createWithContent('relatorio.pdf', "%PDF-1.4\nsynthetic report")]);
        Queue::pushed(ProcessCommitmentImportFileJob::class)->first()->handle(app(ProcessCommitmentImportFileService::class));
        $file = $import->files()->sole();
        $this->assertSame('processed', $file->status->value, $file->last_error ?? '');
        Queue::assertPushed(MatchCommitmentPaymentLinesJob::class);
        Queue::pushed(MatchCommitmentPaymentLinesJob::class)->first()->handle(app(MatchCommitmentPaymentsService::class));
        $line = $file->paymentLines()->where('position', 1)->firstOrFail();
        $this->assertSame(PaymentLineStatus::MATCHED, $line->status, data_get($line->match_diagnostics, 'reason'));
        $this->assertSame($billing->customer_commitment_id, $line->customer_commitment_id);
        $this->assertDatabaseCount('account_receivable_installment_payments', 0);
        $this->receiveLine($line);
        $this->assertDatabaseCount('account_receivable_installment_payments', 1);
        $this->assertDatabaseCount('cash_movements', 1);
        $this->assertSame(3, $file->paymentLines()->whereNull('commitment_receipt_id')->count());
    }
}
