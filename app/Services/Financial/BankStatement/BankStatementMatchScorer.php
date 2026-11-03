<?php

namespace App\Services\Financial\BankStatement;

use App\Models\AccountPayableInstallment;
use App\Models\AccountPayableInstallmentPayment;
use App\Models\AccountReceivableInstallment;
use App\Models\AccountReceivableInstallmentPayment;
use App\Models\BankStatementLine;
use App\Models\CashMovement;

class BankStatementMatchScorer
{
    public function assess(BankStatementLine $line, CashMovement|AccountPayableInstallment|AccountReceivableInstallment $candidate): array
    {
        $text = app(BankStatementMatchText::class);
        $movement = $candidate instanceof CashMovement;
        $target = $this->candidateData($candidate);
        $bankText = implode(' ', array_filter([$line->description, data_get($line->metadata, 'raw.name'), data_get($line->metadata, 'raw.memo')]));
        $bankTokens = $text->tokens($bankText);
        $bankName = $text->tokens(data_get($line->metadata, 'raw.name'));
        $bankDocuments = array_values(array_diff($text->documents($bankText), $text->documents((string) $line->company?->document_number)));
        $targetDocuments = $text->documents($target['document']);
        $documentMatch = array_intersect($bankDocuments, $targetDocuments) !== [];
        $bankReferences = array_filter([$text->reference($line->document_number), ...$text->labeledReferences($bankText)]);
        $targetReferences = array_filter(array_map($text->reference(...), $target['references']));
        $referenceMatch = array_intersect($bankReferences, $targetReferences) !== [];
        $conflicts = [];
        $evidence = [];
        $nameScore = 0;
        $strongName = false;
        $hasComparableName = false;

        foreach ($target['names'] as $name) {
            $tokens = $text->tokens($name);
            $matches = count(array_intersect($tokens, $bankName ?: $bankTokens));
            $hasComparableName = $hasComparableName || count($tokens) >= 2;
            $strong = ($matches >= 2 && $matches / max(1, count($tokens)) >= 0.6)
                || (count($tokens) === 1 && strlen($tokens[0]) >= 5 && $bankName === $tokens);
            $strongName = $strongName || $strong;
            $nameScore = max($nameScore, $strong ? 25 : ($matches > 0 ? 10 : 0));
        }

        if ($bankDocuments !== [] && $targetDocuments !== [] && ! $documentMatch) {
            $conflicts[] = 'tax_document_conflict';
            $evidence[] = 'CPF/CNPJ do extrato diverge da contraparte cadastrada.';
        }
        if (count($bankName) >= 2 && $hasComparableName && $nameScore === 0 && ! $documentMatch && ! $referenceMatch) {
            $conflicts[] = 'counterparty_name_conflict';
            $evidence[] = 'Nome informado pelo banco diverge da contraparte cadastrada.';
        }
        if ($documentMatch) {
            $evidence[] = 'CPF/CNPJ válido corresponde à contraparte.';
        }
        if ($referenceMatch) {
            $evidence[] = 'Referência de documento corresponde ao registro.';
        }
        if ($nameScore > 0) {
            $evidence[] = $strongName ? 'Nome da contraparte compatível após normalização.' : 'Parte do nome da contraparte corresponde ao extrato.';
        }

        $amount = (int) round((float) $line->amount * 100);
        $difference = abs($amount - (int) round((float) $candidate->{$movement ? 'amount' : 'balance_amount'} * 100));
        $days = (int) abs($line->transaction_date->diffInDays($candidate->{$movement ? 'transaction_date' : 'due_date'}));
        $textPoints = min(20, count(array_intersect($bankTokens, $text->tokens($target['text']))) * 4);
        $points = [
            'direction' => $movement ? 20 : 0,
            'amount' => match (true) {
                $difference === 0 => 35,
                $difference <= 100 => 20,
                $difference <= 500 => $movement ? 10 : 12,
                $amount > 0 && $difference * 10000 <= $amount * (int) config('bank_reconciliation.suggestions.relative_amount_window_basis_points', 200) => 10,
                default => 0,
            },
            'date' => match (true) {
                $days === 0 => 20,
                $days <= ($movement ? 3 : 5) => 12,
                $days <= ($movement ? 7 : 15) => 6,
                default => 0,
            },
            'text' => $textPoints,
            'counterparty_name' => $nameScore,
            'tax_document' => $documentMatch ? 40 : 0,
            'reference' => $referenceMatch ? 30 : 0,
            'conflicts' => -(in_array('tax_document_conflict', $conflicts, true) ? 30 : 0) - (in_array('counterparty_name_conflict', $conflicts, true) ? 15 : 0),
        ];
        // These are ranking points, not percentages. Preserve stronger evidence above 100.
        $score = max(0, array_sum($points));
        if ($conflicts !== []) {
            $score = min(39, $score);
        }
        $requiresReview = $difference > 0 || $conflicts !== []
            || ($movement && $days > (int) ($line->financialAccount?->reconciliation_date_tolerance_days ?? 3));
        $largeRelativeDifference = $difference > 0 && $difference * 10000 > $amount * (int) config('bank_reconciliation.suggestions.relative_amount_window_basis_points', 200);
        $confidence = match (true) {
            $conflicts !== [] || $largeRelativeDifference => 'low',
            $score >= (int) config('bank_reconciliation.suggestions.high_confidence_score', 85)
                && ($documentMatch || $referenceMatch || $strongName) && ! $requiresReview && $days <= 7 => 'high',
            $score >= (int) config('bank_reconciliation.suggestions.medium_confidence_score', 45) => 'medium',
            default => 'low',
        };

        return [
            'score' => $score,
            'confidence' => $confidence,
            'ambiguous' => false,
            'requires_review' => $requiresReview,
            'amount_difference_cents' => $difference,
            'date_difference_days' => $days,
            'points' => $points,
            'conflicts' => $conflicts,
            'evidence' => $evidence,
        ];
    }

    private function candidateData(CashMovement|AccountPayableInstallment|AccountReceivableInstallment $candidate): array
    {
        if ($candidate instanceof CashMovement) {
            $payment = match ($candidate->origin_type) {
                AccountPayableInstallmentPayment::class => $candidate->payablePayment,
                AccountReceivableInstallmentPayment::class => $candidate->receivablePayment,
                default => null,
            };
            $parent = $payment instanceof AccountPayableInstallmentPayment ? $payment->installment?->accountPayable : $payment?->installment?->accountReceivable;
            if ((int) $parent?->company_id !== (int) $candidate->company_id
                || (int) $payment?->company_id !== (int) $candidate->company_id
                || (int) $payment?->installment?->company_id !== (int) $candidate->company_id) {
                $parent = null;
            }
            $partner = $candidate->counterpartyPartner ?? ($payment instanceof AccountPayableInstallmentPayment ? $parent?->supplier : $parent?->customer);
            $names = array_filter([$partner?->name, $candidate->manual_counterparty_name, data_get($candidate->participants_snapshot, 'counterparty_partner_name'), $parent?->manual_counterparty_name]);
        } else {
            $parent = $candidate instanceof AccountPayableInstallment ? $candidate->accountPayable : $candidate->accountReceivable;
            $partner = $candidate instanceof AccountPayableInstallment ? $parent?->supplier : $parent?->customer;
            $names = array_filter([$partner?->name, $parent?->manual_counterparty_name]);
        }

        $description = implode(' ', array_filter([$candidate->description, $candidate->notes, $parent?->description, ...$names]));

        return [
            'names' => array_values($names),
            'document' => (string) $partner?->document_number,
            'references' => [$parent?->document_number, $parent?->note_number, $parent?->bank_slip_number, ...app(BankStatementMatchText::class)->labeledReferences($description)],
            'text' => $description,
        ];
    }
}
