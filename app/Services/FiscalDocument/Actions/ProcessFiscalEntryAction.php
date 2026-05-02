<?php

namespace App\Services\FiscalDocument\Actions;

use App\Enum\AccountPayable\Status as AccountPayableStatus;
use App\Enum\Payment\Condition;
use App\Enum\Payment\Method as PaymentMethod;
use App\Enum\StockMovement\Type as MovementType;
use App\Models\FiscalDocument;
use App\Models\ProductStock;
use App\Services\AccountPayable\AccountPayableService;
use App\Services\StockMovement\StockMovementService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class ProcessFiscalEntryAction
{
    public function __construct(
        private readonly StockMovementService  $stockMovementService  = new StockMovementService(),
        private readonly AccountPayableService $accountPayableService = new AccountPayableService(),
    ) {}

    /**
     * Processa a confirmação de uma nota de entrada:
     * 1. Gera movimentações de estoque para produtos com has_stock_control = true
     * 2. Cria contas a pagar conforme o método e condição de pagamento
     *
     * @param  FiscalDocument $document
     * @param  array{
     *     payment_method: string,
     *     payment_condition: string,
     *     due_date: string,
     *     description: ?string,
     * } $paymentData
     * @param  int $userId
     * @return array{stock_movements: int, payables: int, errors: string[]}
     */
    public function execute(FiscalDocument $document, array $paymentData, int $userId): array
    {
        $document->loadMissing(['items.product', 'items.product.stock']);

        $result = [
            'stock_movements' => 0,
            'payables'        => 0,
            'errors'          => [],
        ];

        /* ───────────────────────────────────────────────────
         | 1. Movimentações de estoque
         ─────────────────────────────────────────────────── */
        foreach ($document->items as $item) {
            if (! $item->product_id) {
                Log::debug('ProcessFiscalEntryAction: Item sem produto', [
                    'metodo' => __METHOD__ . '@' . __LINE__,
                    'item'   => $item,
                ]);
                continue;
            }

            $product = $item->product;
            if (! $product || ! $product->has_stock_control) {
                Log::debug('ProcessFiscalEntryAction: Produto sem controle de estoque', [
                    'metodo'  => __METHOD__ . '@' . __LINE__,
                    'product' => $product,
                ]);
                continue;
            }

            $stock = ProductStock::where('product_id', $item->product_id)
                ->where('company_id', $document->company_id)
                ->first();

            if (! $stock) {
                $result['errors'][] = "Produto #{$product->product_code} sem estoque cadastrado. Movimentação ignorada.";
                Log::warning('ProcessFiscalEntryAction: ProductStock não encontrado', [
                    'metodo'      => __METHOD__ . '@' . __LINE__,
                    'product_id'  => $item->product_id,
                    'company_id'  => $document->company_id,
                ]);
                continue;
            }

            $movement = $this->stockMovementService->create([
                'product_stock_id' => $stock->id,
                'product_id'       => $item->product_id,
                'company_id'       => $document->company_id,
                'type'             => MovementType::ENTRY->value,
                'operational_unit' => $item->taxable_unit ?: ($item->unit_of_measure ?? $product->unit?->value),
                'quantity'         => (float) ($item->taxable_quantity ?? $item->quantity),
                'unit_price'       => (float) $item->unit_price,
                'reason'           => "Nota de Entrada #{$document->document_number} — Produto: {$product->product_code}",
                'source_type'      => 'fiscal_document',
                'source_id'        => $document->id,
            ], $userId);

            if ($this->stockMovementService->hasError() || ! $movement) {
                $result['errors'][] = "Erro ao registrar movimentação para produto {$product->product_code}: "
                    . $this->stockMovementService->getMessage();
            } else {
                $result['stock_movements']++;
            }
        }

        /* ───────────────────────────────────────────────────
         | 2. Contas a pagar
         ─────────────────────────────────────────────────── */
        $condition   = Condition::from($paymentData['payment_condition']);
        $totalAmount = $document->items->sum(fn($i) => (float) $i->total_price);
        $description = $paymentData['description'] ?? "NF #{$document->document_number}";
        $baseDate    = Carbon::parse($paymentData['due_date']);

        $installments = $this->buildInstallments($condition, $totalAmount, $baseDate);

        foreach ($installments as $index => $installment) {
            $sequence = (string) ($index + 1);

            $payable = $this->accountPayableService->create([
                'supplier_id'         => $document->customer_id,
                'company_id'          => $document->company_id,
                'fiscal_document_id'  => $document->id,
                'sequence_number'     => $sequence,
                'status'              => AccountPayableStatus::PENDING->value,
                'payment_method'      => $paymentData['payment_method'],
                'due_date'            => $installment['due_date']->format('Y-m-d'),
                'due_amount'          => $installment['amount'],
                'description'         => $description . (count($installments) > 1 ? " ({$sequence}/" . count($installments) . ')' : ''),
                'document_number'     => $document->document_number,
            ], $userId);

            if ($this->accountPayableService->hasError() || ! $payable) {
                $result['errors'][] = "Erro ao criar parcela {$sequence}: "
                    . $this->accountPayableService->getMessageUser();
            } else {
                $result['payables']++;
            }
        }

        return $result;
    }

    /**
     * Gera a lista de parcelas a partir da condição de pagamento.
     *
     * @return array<int, array{due_date: Carbon, amount: float}>
     */
    private function buildInstallments(Condition $condition, float $total, Carbon $baseDate): array
    {
        // Condição personalizada → sem parcelas automáticas
        if ($condition === Condition::CUSTOM) {
            return [];
        }

        $installments = $condition->installments();

        // Condições com 0 installments = à vista ou prazo único
        if ($installments === 0) {
            $days = $condition->days();
            return [[
                'due_date' => (clone $baseDate)->addDays($days),
                'amount'   => $total,
            ]];
        }

        // Condições multi-vencimento (30/60, 30/60/90, 30/60/90/120)
        $isMultiDeadline = in_array($condition, [
            Condition::DAYS_30_60,
            Condition::DAYS_30_60_90,
            Condition::DAYS_30_60_90_120,
        ]);

        if ($isMultiDeadline) {
            return $this->buildMultiDeadlineInstallments($condition, $total, $baseDate);
        }

        // Parcelado (INSTALLMENTS_NX) — parcelas iguais com 30 dias de intervalo
        $amount  = round($total / $installments, 2);
        $diff    = $total - ($amount * ($installments - 1));
        $result  = [];

        for ($i = 0; $i < $installments; $i++) {
            $result[] = [
                'due_date' => (clone $baseDate)->addDays(30 * ($i + 1)),
                'amount'   => $i === $installments - 1 ? $diff : $amount, // última parcela absorve arredondamento
            ];
        }

        return $result;
    }

    /**
     * Gera parcelas para condições do tipo 30/60, 30/60/90 etc.
     *
     * @return array<int, array{due_date: Carbon, amount: float}>
     */
    private function buildMultiDeadlineInstallments(Condition $condition, float $total, Carbon $baseDate): array
    {
        $deadlineSets = match ($condition) {
            Condition::DAYS_30_60            => [30, 60],
            Condition::DAYS_30_60_90         => [30, 60, 90],
            Condition::DAYS_30_60_90_120     => [30, 60, 90, 120],
            default                          => [30],
        };

        $count  = count($deadlineSets);
        $amount = round($total / $count, 2);
        $diff   = $total - ($amount * ($count - 1));
        $result = [];

        foreach ($deadlineSets as $idx => $days) {
            $result[] = [
                'due_date' => (clone $baseDate)->addDays($days),
                'amount'   => $idx === $count - 1 ? $diff : $amount,
            ];
        }

        return $result;
    }
}
