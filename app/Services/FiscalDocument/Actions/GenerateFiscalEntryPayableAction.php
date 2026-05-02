<?php

namespace App\Services\FiscalDocument\Actions;

use App\Enum\AccountPayable\Status as AccountPayableStatus;
use App\Enum\Payment\Condition;
use App\Models\FiscalDocument;
use App\Services\AccountPayable\AccountPayableService;
use Carbon\Carbon;

class GenerateFiscalEntryPayableAction
{
    public function __construct(
        private readonly AccountPayableService $accountPayableService = new AccountPayableService(),
    ) {}

    /**
     * @param  array{
     *     payment_method: string,
     *     payment_condition: string,
     *     due_date: string,
     *     description: ?string,
     * } $paymentData
     * @return array{payables: int, errors: string[]}
     */
    public function execute(FiscalDocument $document, array $paymentData, int $userId): array
    {
        $document->loadMissing('items');

        $condition = Condition::from($paymentData['payment_condition']);
        $totalAmount = $document->items->sum(fn ($item) => (float) $item->total_price);
        $description = $paymentData['description'] ?? "NF #{$document->document_number}";
        $baseDate = Carbon::parse($paymentData['due_date']);

        $installments = $this->buildInstallments($condition, $totalAmount, $baseDate);
        $result = [
            'payables' => 0,
            'errors' => [],
        ];

        foreach ($installments as $index => $installment) {
            $sequence = (string) ($index + 1);

            $payable = $this->accountPayableService->create([
                'supplier_id' => $document->customer_id,
                'company_id' => $document->company_id,
                'fiscal_document_id' => $document->id,
                'sequence_number' => $sequence,
                'status' => AccountPayableStatus::PENDING->value,
                'payment_method' => $paymentData['payment_method'],
                'due_date' => $installment['due_date']->format('Y-m-d'),
                'due_amount' => $installment['amount'],
                'description' => $description . (count($installments) > 1 ? " ({$sequence}/" . count($installments) . ')' : ''),
                'document_number' => $document->document_number,
            ], $userId);

            if ($this->accountPayableService->hasError() || ! $payable) {
                $result['errors'][] = "Erro ao criar parcela {$sequence}: "
                    . $this->accountPayableService->getMessageUser();

                continue;
            }

            $result['payables']++;
        }

        return $result;
    }

    /**
     * @return array<int, array{due_date: Carbon, amount: float}>
     */
    private function buildInstallments(Condition $condition, float $total, Carbon $baseDate): array
    {
        if ($condition === Condition::CUSTOM) {
            return [];
        }

        $installments = $condition->installments();

        if ($installments === 0) {
            return [[
                'due_date' => (clone $baseDate)->addDays($condition->days()),
                'amount' => $total,
            ]];
        }

        $isMultiDeadline = in_array($condition, [
            Condition::DAYS_30_60,
            Condition::DAYS_30_60_90,
            Condition::DAYS_30_60_90_120,
        ], true);

        if ($isMultiDeadline) {
            return $this->buildMultiDeadlineInstallments($condition, $total, $baseDate);
        }

        $amount = round($total / $installments, 2);
        $diff = $total - ($amount * ($installments - 1));
        $result = [];

        for ($index = 0; $index < $installments; $index++) {
            $result[] = [
                'due_date' => (clone $baseDate)->addDays(30 * ($index + 1)),
                'amount' => $index === $installments - 1 ? $diff : $amount,
            ];
        }

        return $result;
    }

    /**
     * @return array<int, array{due_date: Carbon, amount: float}>
     */
    private function buildMultiDeadlineInstallments(Condition $condition, float $total, Carbon $baseDate): array
    {
        $deadlineSets = match ($condition) {
            Condition::DAYS_30_60 => [30, 60],
            Condition::DAYS_30_60_90 => [30, 60, 90],
            Condition::DAYS_30_60_90_120 => [30, 60, 90, 120],
            default => [30],
        };

        $count = count($deadlineSets);
        $amount = round($total / $count, 2);
        $diff = $total - ($amount * ($count - 1));
        $result = [];

        foreach ($deadlineSets as $index => $days) {
            $result[] = [
                'due_date' => (clone $baseDate)->addDays($days),
                'amount' => $index === $count - 1 ? $diff : $amount,
            ];
        }

        return $result;
    }
}
