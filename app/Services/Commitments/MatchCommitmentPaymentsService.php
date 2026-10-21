<?php

namespace App\Services\Commitments;

use App\Enum\Commitment\PaymentLineStatus;
use App\Enum\Commitment\ReceiptStatus;
use App\Jobs\MatchCommitmentPaymentLinesJob;
use App\Models\AccountReceivable;
use App\Models\AccountReceivableInstallment;
use App\Models\AccountReceivableInstallmentPayment;
use App\Models\CommitmentImportFile;
use App\Models\CommitmentPaymentLine;
use App\Models\CommitmentReceipt;
use App\Models\CustomerCommitment;

class MatchCommitmentPaymentsService
{
    public function refresh(int $companyId, int $lineId, int $userId): CommitmentPaymentLine
    {
        return app(CommitmentPaymentContext::class)->transaction($companyId, $lineId, $userId, function (CommitmentPaymentLine $line) {
            $result = $this->analyze($line, true);
            if ($result['receipt_needs_review'] ?? false) {
                CommitmentReceipt::forCustomer($line->company_id, $line->customer_id)->whereKey($result['receipt_id'])->update(['status' => ReceiptStatus::NEEDS_REVIEW->value]);
            }
            $line->update(['status' => $result['status'], 'customer_commitment_id' => $line->commitment_receipt_id ? $line->customer_commitment_id : $result['commitment_id'],
                'commitment_receipt_id' => $line->commitment_receipt_id ?? (in_array($result['status'], ['received', 'already_received'], true) ? $result['receipt_id'] : null),
                'match_diagnostics' => $result, 'matched_at' => now()]);

            return $line;
        });
    }

    public function refreshFile(int $companyId, int $fileId, int $userId): void
    {
        $file = CommitmentImportFile::forCompany($companyId)->where('type', 'payments')->findOrFail($fileId);
        app(CommitmentImportAccess::class)->authorize($companyId, $file->customer_id, $userId);
        foreach ($file->paymentLines()->forCustomer($companyId, $file->customer_id)->orderBy('id')->pluck('id') as $id) {
            $this->refresh($companyId, $id, $userId);
        }
    }

    public function queueFile(int $companyId, int $fileId, int $userId): void
    {
        $file = CommitmentImportFile::forCompany($companyId)->where('type', 'payments')->findOrFail($fileId);
        app(CommitmentImportAccess::class)->authorize($companyId, $file->customer_id, $userId);
        foreach ($file->paymentLines()->forCustomer($companyId, $file->customer_id)->orderBy('id')->pluck('id')->chunk(25) as $ids) {
            dispatch(new MatchCommitmentPaymentLinesJob($companyId, $file->customer_id, $fileId, $ids->all(), $userId))->afterCommit();
        }
    }

    public function analyze(CommitmentPaymentLine $line, bool $lock = false): array
    {
        $result = ['status' => 'unmatched', 'reason' => 'Empenho não localizado para a empresa e cliente selecionados.', 'commitment_id' => null, 'receipt_id' => null,
            'invoice_id' => null, 'document_id' => null, 'balance_cents' => null, 'installments' => [], 'existing_payment_ids' => [], 'can_link_existing' => false];
        if ($line->status === PaymentLineStatus::IGNORED) {
            return [...$result, 'status' => 'ignored', 'reason' => $line->review_reason ?? 'Linha ignorada.'];
        }
        $issues = CommitmentPaymentContext::reportIssues($line->importFile);
        if ($issues !== [] && data_get($line->importFile->extracted_data, 'review.acknowledged_issues') !== $issues) {
            return [...$result, 'status' => 'divergent', 'reason' => 'Confira a integridade do relatório antes da baixa: '.implode(' ', $issues)];
        }
        $issues = data_get($line->original_data, 'issues', []);
        if ($issues !== [] && data_get($line->corrected_data, 'acknowledged_issues') !== $issues) {
            return [...$result, 'status' => 'divergent', 'reason' => 'Revise os dados e confirme as pendências desta linha: '.implode(' ', $issues)];
        }
        if ((int) $line->getRawOriginal('amount') <= 0 || ! $line->payment_date || $line->payment_date->isFuture()) {
            return [...$result, 'status' => 'divergent', 'reason' => 'Data de pagamento futura ou valor inválido. Revise a linha.'];
        }
        $commitment = CustomerCommitment::forCustomer($line->company_id, $line->customer_id)
            ->where('number', CommitmentMatchValues::identifier($line->commitment_number))->where('fiscal_year', $line->fiscal_year)
            ->when($lock, fn ($query) => $query->lockForUpdate())->first();
        if (! $commitment) {
            return $result;
        }
        $result['commitment_id'] = $commitment->id;
        if ($line->patrimony && CommitmentMatchValues::identifier($line->patrimony) !== CommitmentMatchValues::identifier((string) $commitment->patrimony)) {
            return [...$result, 'status' => 'divergent', 'reason' => 'O patrimônio do relatório difere do empenho.'];
        }
        $billing = $commitment->billing()->when($lock, fn ($query) => $query->lockForUpdate())->first();
        $invoice = $billing?->invoice()->where('company_id', $line->company_id)->where('customer_id', $line->customer_id)
            ->when($lock, fn ($query) => $query->lockForUpdate())->first();
        if (! $invoice || ! $invoice->confirmed || $invoice->canceled) {
            return [...$result, 'status' => 'divergent', 'reason' => 'O empenho precisa ter uma fatura confirmada e não cancelada.'];
        }
        $result['invoice_id'] = $invoice->id;
        if ($invoice->requisitions()->exists() || $invoice->serviceOrders()->count() !== 1 || ! $invoice->serviceOrders()->whereKey($billing->service_order_id)->exists()) {
            return [...$result, 'status' => 'divergent', 'reason' => 'A fatura não corresponde exclusivamente à OS deste empenho. Revise o faturamento.'];
        }
        $documents = $invoice->fiscalDocuments()->where('company_id', $line->company_id)->where('customer_id', $line->customer_id)
            ->where('document_type', 'nfse')->when($lock, fn ($query) => $query->lockForUpdate())->get();
        $document = $documents->first();
        if ($invoice->fiscalDocuments()->count() !== 1 || $documents->count() !== 1 || ! filled($document?->document_number) || ! filled($line->fiscal_document_number)
            || CommitmentMatchValues::identifier((string) $document->document_number) !== CommitmentMatchValues::identifier($line->fiscal_document_number)
            || $document->canceled || $document->nfse_status?->value === 'canceled'
            || ($line->fiscal_document_date && $line->fiscal_document_date->toDateString() !== $document->issued_at?->toDateString())) {
            return [...$result, 'status' => 'divergent', 'reason' => 'Número, data ou situação da NFS-e incompatível com a fatura do empenho.'];
        }
        $result['document_id'] = $document->id;
        $accounts = AccountReceivable::query()->where('company_id', $line->company_id)->where('customer_id', $line->customer_id)->where('invoice_id', $invoice->id)
            ->orderBy('id')->when($lock, fn ($query) => $query->lockForUpdate())->get();
        if (AccountReceivable::query()->where('invoice_id', $invoice->id)->where(fn ($query) => $query->where('company_id', '!=', $line->company_id)->orWhere('customer_id', '!=', $line->customer_id)->orWhereNull('customer_id'))->exists()) {
            return [...$result, 'status' => 'divergent', 'reason' => 'Uma conta vinculada à fatura pertence a outro contexto. Revise os vínculos financeiros.'];
        }
        $installments = AccountReceivableInstallment::query()->where('company_id', $line->company_id)->whereIn('account_receivable_id', $accounts->modelKeys())
            ->orderBy('id')->when($lock, fn ($query) => $query->lockForUpdate())->get();
        $payments = AccountReceivableInstallmentPayment::query()->where('company_id', $line->company_id)->whereIn('account_receivable_installment_id', $installments->modelKeys())
            ->orderBy('id')->when($lock, fn ($query) => $query->lockForUpdate())->get()->keyBy('id');
        if (AccountReceivableInstallment::query()->whereIn('account_receivable_id', $accounts->modelKeys())->where('company_id', '!=', $line->company_id)->exists()
            || AccountReceivableInstallmentPayment::query()->whereIn('account_receivable_installment_id', $installments->modelKeys())->where('company_id', '!=', $line->company_id)->exists()) {
            return [...$result, 'status' => 'divergent', 'reason' => 'Há parcela ou pagamento de outra empresa vinculado à fatura. Revise os vínculos financeiros.'];
        }
        if ($accounts->isEmpty() || $installments->isEmpty() || $accounts->contains(fn ($account) => $account->status->value === 'cancelled')
            || $installments->contains(fn ($item) => $item->status->value === 'cancelled')
            || (int) $installments->sum(fn ($item) => (int) $item->getRawOriginal('original_amount')) !== (int) $commitment->getRawOriginal('amount')) {
            return [...$result, 'status' => 'divergent', 'reason' => 'Confira as contas, parcelas, cancelamentos e o valor original da fatura.'];
        }
        $balance = 0;
        foreach ($installments->sortBy([['due_date', 'asc'], ['id', 'asc']]) as $installment) {
            $rows = $payments->where('account_receivable_installment_id', $installment->id);
            $received = (int) $rows->sum(fn ($payment) => (int) $payment->getRawOriginal('amount') - (int) $payment->getRawOriginal('returned_amount'));
            $due = (int) $installment->getRawOriginal('original_amount') + (int) $rows->sum(fn ($payment) => (int) $payment->getRawOriginal('interest_amount')
                + (int) $payment->getRawOriginal('fine_amount') - (int) $payment->getRawOriginal('discount_amount'));
            $open = $due - $received;
            if ($open < 0 || $open !== (int) $installment->getRawOriginal('balance_amount') || $received !== (int) $installment->getRawOriginal('received_amount')) {
                return [...$result, 'status' => 'divergent', 'reason' => 'O saldo das parcelas não confere com os pagamentos atuais. Revise no financeiro.'];
            }
            $result['installments'][] = ['id' => $installment->id, 'account_id' => $installment->account_receivable_id, 'due_date' => $installment->due_date?->toDateString(),
                'balance_cents' => $open, 'competence_date' => $installment->competence_date?->toDateString()];
            $balance += $open;
        }
        $result['balance_cents'] = $balance;
        $receipt = $commitment->receipt()->when($lock, fn ($query) => $query->lockForUpdate())->first();
        if ($receipt) {
            $result['receipt_id'] = $receipt->id;
            $links = $receipt->paymentLinks()->when($lock, fn ($query) => $query->lockForUpdate())->get();
            $valid = $receipt->status === ReceiptStatus::RECEIVED && (int) $receipt->invoice_id === (int) $invoice->id && $links->isNotEmpty() && $balance === 0;
            foreach ($links as $link) {
                $payment = $payments->get($link->payment_id);
                $valid = $valid && $payment && data_get($link->payment_snapshot, 'evidence') === self::paymentEvidence($payment);
            }
            if (! $valid) {
                return [...$result, 'status' => 'divergent', 'receipt_needs_review' => true, 'reason' => 'O recebimento vinculado foi removido, estornado ou alterado. Revise no financeiro; não será criada outra baixa.'];
            }
            if ((int) $receipt->getRawOriginal('amount') !== (int) $line->getRawOriginal('amount') || $receipt->payment_date->toDateString() !== $line->payment_date->toDateString()) {
                return [...$result, 'status' => 'divergent', 'reason' => 'O empenho já tem quitação vinculada, mas data/valor do relatório são diferentes.'];
            }

            return [...$result, 'status' => $line->status === PaymentLineStatus::RECEIVED ? 'received' : 'already_received', 'reason' => 'Quitação já vinculada. Nenhum novo recebimento será registrado.'];
        }
        if ($balance === 0) {
            $existing = $payments->filter(fn ($payment) => $payment->payment_date->toDateString() === $line->payment_date->toDateString());
            $sameAmount = (int) $existing->sum(fn ($payment) => (int) $payment->getRawOriginal('amount') - (int) $payment->getRawOriginal('returned_amount')) === (int) $line->getRawOriginal('amount');
            $canLink = $sameAmount && $existing->isNotEmpty() && $existing->every(fn ($payment) => $payment->financial_account_id && (int) $payment->getRawOriginal('returned_amount') === 0)
                && $existing->pluck('financial_account_id')->unique()->count() === 1;

            return [...$result, 'status' => 'already_received', 'can_link_existing' => $canLink, 'existing_account_id' => $canLink ? $existing->first()->financial_account_id : null,
                'existing_payment_ids' => $canLink ? $existing->modelKeys() : [],
                'reason' => $canLink ? 'Conta já recebida com a mesma data/valor. Vincule os pagamentos existentes, sem nova baixa.' : 'Conta já recebida. Data/valor ou contas não permitem vínculo automático; revise os pagamentos existentes.'];
        }
        if ($balance !== (int) $line->getRawOriginal('amount')) {
            return [...$result, 'status' => 'divergent', 'reason' => 'O valor do relatório difere do saldo aberto. Parcial, retenção, juros ou desconto exigem revisão no financeiro.'];
        }

        return [...$result, 'status' => 'matched', 'reason' => 'Empenho, NFS-e e saldo aberto conferem. Disponível para quitação integral.'];
    }

    public static function paymentEvidence(AccountReceivableInstallmentPayment $payment): array
    {
        return ['id' => $payment->id, 'installment_id' => $payment->account_receivable_installment_id, 'company_id' => $payment->company_id,
            'financial_account_id' => $payment->financial_account_id, 'payment_date' => $payment->payment_date->toDateString(),
            'amount_cents' => (int) $payment->getRawOriginal('amount'), 'returned_cents' => (int) $payment->getRawOriginal('returned_amount'),
            'interest_cents' => (int) $payment->getRawOriginal('interest_amount'), 'fine_cents' => (int) $payment->getRawOriginal('fine_amount'), 'discount_cents' => (int) $payment->getRawOriginal('discount_amount')];
    }
}
