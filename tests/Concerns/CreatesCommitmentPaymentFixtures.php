<?php

namespace Tests\Concerns;

use App\Enum\Payment\Condition;
use App\Enum\Payment\Method;
use App\Models\CommitmentBilling;
use App\Models\CommitmentImport;
use App\Models\CommitmentImportFile;
use App\Models\CommitmentPaymentLine;
use App\Models\CommitmentSetting;
use App\Models\FinancialAccount;
use App\Services\Commitments\ApproveCommitmentMatchService;
use App\Services\Commitments\ProcessCommitmentBillingBatchService;
use App\Services\Commitments\RequestCommitmentBillingService;
use Illuminate\Support\Facades\Queue;

trait CreatesCommitmentPaymentFixtures
{
    use CreatesCommitmentReviewFixtures;

    protected FinancialAccount $cashAccount;

    protected function createPaymentContext(): void
    {
        $this->createReviewContext();
        $this->actingAs($this->user);
        Queue::fake();
        $this->cashAccount = FinancialAccount::create(['company_id' => $this->company->id, 'name' => 'Conta de recebimentos', 'type' => 'cash', 'opening_balance' => 0, 'is_active' => true, 'created_by' => $this->user->id]);
        CommitmentSetting::query()->update(['payment_method' => Method::BANK_TRANSFER->value, 'payment_condition' => Condition::CASH->value, 'financial_account_id' => $this->cashAccount->id]);
    }

    protected function billedCommitment(Condition $condition = Condition::CASH): CommitmentBilling
    {
        CommitmentSetting::query()->update(['payment_condition' => $condition->value]);
        $record = $this->commitment();
        $order = $this->order();
        $billing = app(ApproveCommitmentMatchService::class)->approve($this->company->id, $record->id, $order->id, $this->user->id);
        $batch = app(RequestCommitmentBillingService::class)->request($this->company->id, [$record->id], $this->user->id);
        app(ProcessCommitmentBillingBatchService::class)->process($batch->id, $batch->company_id, $batch->run_token);
        $billing->refresh();
        $this->assertNotNull($billing->invoice_id, $billing->last_error ?? '');
        $billing->invoice->fiscalDocuments()->sole()->update(['document_number' => (string) (247 + $this->fixtureSequence), 'issued_at' => '2026-08-01']);

        return $billing->fresh();
    }

    protected function paymentLine(CommitmentBilling $billing, array $attributes = [], ?CommitmentImportFile $file = null): CommitmentPaymentLine
    {
        if (! $file) {
            $import = CommitmentImport::create(['company_id' => $this->company->id, 'customer_id' => $this->customer->id, 'type' => 'payments']);
            $file = $import->files()->create(['company_id' => $this->company->id, 'customer_id' => $this->customer->id, 'type' => 'payments',
                'original_name' => 'pagamentos-'.$import->id.'.pdf', 'sha256' => hash('sha256', 'report-'.$import->id), 'status' => 'processed', 'extracted_data' => ['issues' => []]]);
        }
        $document = $billing->invoice->fiscalDocuments()->sole();

        return $file->paymentLines()->create(['company_id' => $this->company->id, 'customer_id' => $this->customer->id, 'position' => $file->paymentLines()->count() + 1,
            'page_start' => 1, 'page_end' => 1, 'commitment_number' => $billing->commitment->number, 'fiscal_year' => 2026,
            'patrimony' => $billing->commitment->patrimony, 'fiscal_document_number' => $document->document_number, 'fiscal_document_date' => '2026-08-01',
            'payment_date' => '2026-08-27', 'amount' => 400, 'object' => 'Pagamento PAT '.$billing->commitment->patrimony,
            'original_data' => ['issues' => [], 'fiscal_documents' => [['number' => $document->document_number, 'issued_at' => '2026-08-01', 'amount_cents' => 40000]]], ...$attributes])->fresh();
    }
}
