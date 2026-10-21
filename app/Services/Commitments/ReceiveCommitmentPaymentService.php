<?php

namespace App\Services\Commitments;

use App\Enum\Commitment\PaymentLineStatus;
use App\Enum\Commitment\ReceiptStatus;
use App\Models\AccountReceivableInstallment;
use App\Models\AccountReceivableInstallmentPayment;
use App\Models\CommitmentPaymentLine;
use App\Models\CommitmentReceipt;
use App\Models\FinancialAccount;
use App\Services\AccountReceivable\AccountReceivableService;
use App\Services\Audit\AuditRecorder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ReceiveCommitmentPaymentService
{
    public function receive(int $companyId, int $lineId, int $userId, int $financialAccountId, ?string $competenceDate = null, bool $linkExisting = false): CommitmentReceipt
    {
        Validator::make(['competence_date' => $competenceDate], ['competence_date' => 'nullable|date_format:Y-m-d'])->validate();
        try {
            return app(CommitmentPaymentContext::class)->transaction($companyId, $lineId, $userId, function (CommitmentPaymentLine $line) use ($userId, $financialAccountId, $competenceDate, $linkExisting) {
                $result = app(MatchCommitmentPaymentsService::class)->analyze($line, true);
                if (in_array($result['status'], ['received', 'already_received'], true) && $result['receipt_id'] && ! ($result['receipt_needs_review'] ?? false)) {
                    $receipt = CommitmentReceipt::forCustomer($line->company_id, $line->customer_id)->findOrFail($result['receipt_id']);
                    // Only exact duplicates may reuse the existing settlement.
                    if ($receipt->payment_date->toDateString() !== $line->payment_date->toDateString() || (int) $receipt->getRawOriginal('amount') !== (int) $line->getRawOriginal('amount')) {
                        $this->invalid('O relatório difere da quitação existente. Revise a linha.');
                    }
                    $line->update(['customer_commitment_id' => $receipt->customer_commitment_id, 'commitment_receipt_id' => $receipt->id,
                        'status' => $line->status === PaymentLineStatus::RECEIVED ? PaymentLineStatus::RECEIVED : PaymentLineStatus::ALREADY_RECEIVED,
                        'match_diagnostics' => $result, 'matched_at' => now()]);

                    return $receipt;
                }
                $allowed = $linkExisting ? $result['status'] === 'already_received' && $result['can_link_existing'] : $result['status'] === 'matched';
                if (! $allowed) {
                    $this->invalid($result['reason']);
                }
                // Account/header/installment locks were acquired by analyze() before the cash account.
                $account = FinancialAccount::query()->where('company_id', $line->company_id)->where('is_active', true)->lockForUpdate()->find($financialAccountId);
                if (! $account) {
                    $this->invalid('Selecione uma conta financeira ativa da empresa atual.');
                }
                if ($linkExisting && (int) $result['existing_account_id'] !== $financialAccountId) {
                    $this->invalid('Selecione a conta financeira dos pagamentos existentes.');
                }
                $receipt = CommitmentReceipt::create([
                    'company_id' => $line->company_id, 'customer_id' => $line->customer_id, 'customer_commitment_id' => $result['commitment_id'],
                    'invoice_id' => $result['invoice_id'], 'financial_account_id' => $account->id, 'payment_date' => $line->payment_date->toDateString(),
                    'amount' => $line->amount, 'status' => ReceiptStatus::PENDING, 'created_by' => $userId,
                    'idempotency_key' => hash('sha256', implode(':', [$line->company_id, $line->customer_id, $result['commitment_id'], $result['invoice_id'], $line->payment_date->toDateString(), $line->getRawOriginal('amount')])),
                ]);
                if ($linkExisting) {
                    $payments = AccountReceivableInstallmentPayment::query()->where('company_id', $line->company_id)->whereIn('id', $result['existing_payment_ids'])->orderBy('id')->lockForUpdate()->get();
                } else {
                    $payments = collect();
                    foreach ($result['installments'] as $row) {
                        if ($row['balance_cents'] <= 0) {
                            continue;
                        }
                        $installment = AccountReceivableInstallment::query()->where('company_id', $line->company_id)->where('account_receivable_id', $row['account_id'])->findOrFail($row['id']);
                        $service = app(AccountReceivableService::class);
                        $payment = $service->registerInstallmentPayment($installment, $row['balance_cents'] / 100, $line->payment_date->toDateString(), [
                            'origin' => 'commitment_report', 'financial_account_id' => $account->id, 'competence_date' => $competenceDate, 'user_id' => $userId,
                            'description' => 'Empenho '.$line->commitment_number.'/'.$line->fiscal_year.' — relatório #'.$line->commitment_import_file_id.', linha '.$line->position,
                            'notes' => 'Origem: relatório de pagamentos de empenhos; arquivo #'.$line->commitment_import_file_id.'; páginas '.$line->page_start.'–'.$line->page_end.'.',
                        ]);
                        if (! $payment || $service->hasError()) {
                            // False/null from a nested workflow must abort the outer settlement transaction too.
                            $this->invalid($service->getMessageUser() ?: 'Falha na baixa de uma parcela. Nenhum recebimento deste empenho foi confirmado.');
                        }
                        $payments->push($payment);
                    }
                }
                if ($payments->isEmpty() || (int) $payments->sum(fn ($payment) => (int) $payment->getRawOriginal('amount') - (int) $payment->getRawOriginal('returned_amount')) !== (int) $line->getRawOriginal('amount')) {
                    $this->invalid('Os pagamentos não totalizam o valor do relatório. Revise a quitação.');
                }
                foreach ($payments as $payment) {
                    $receipt->paymentLinks()->create(['company_id' => $line->company_id, 'customer_id' => $line->customer_id, 'payment_id' => $payment->id,
                        'payment_snapshot' => ['evidence' => MatchCommitmentPaymentsService::paymentEvidence($payment), 'source_line_id' => $line->id, 'linked_existing' => $linkExisting]]);
                }
                $receipt->update(['status' => ReceiptStatus::RECEIVED]);
                $line->update(['customer_commitment_id' => $result['commitment_id'], 'commitment_receipt_id' => $receipt->id,
                    'status' => $linkExisting ? PaymentLineStatus::ALREADY_RECEIVED : PaymentLineStatus::RECEIVED]);
                app(AuditRecorder::class)->recordModelEvent($line, $linkExisting ? 'commitment.receipt_linked' : 'commitment.payment_received',
                    $linkExisting ? 'Relatório vinculado a recebimentos existentes, sem nova baixa.' : 'Pagamento do empenho recebido pelo serviço financeiro existente.', actorUserId: $userId,
                    metadata: ['receipt_id' => $receipt->id, 'invoice_id' => $result['invoice_id'], 'payment_ids' => $payments->pluck('id')->all()]);
                $final = app(MatchCommitmentPaymentsService::class)->analyze($line->fresh(), true);
                if (! in_array($final['status'], ['received', 'already_received'], true)) {
                    $this->invalid('A quitação não ficou consistente com o saldo final. Nenhuma baixa foi confirmada.');
                }
                $line->update(['match_diagnostics' => $final, 'matched_at' => now()]);

                return $receipt;
            });
        } catch (ValidationException $exception) {
            // Publish current diagnostics after rollback, including missing/reversed settlement evidence.
            app(MatchCommitmentPaymentsService::class)->refresh($companyId, $lineId, $userId);
            throw $exception;
        }
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['payment' => $message]);
    }
}
