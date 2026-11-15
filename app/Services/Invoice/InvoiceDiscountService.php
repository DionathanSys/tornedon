<?php

namespace App\Services\Invoice;

use App\Enum\Invoice\InvoiceAction;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class InvoiceDiscountService
{
    /** Travel and informational production items do not participate. Values are integer cents. */
    public function rows(Invoice $invoice, bool $lock = false): array
    {
        $rows = [];
        foreach (['requisitions' => ['requisition', 'product'], 'serviceOrders' => ['service_order', 'service']] as $relation => [$type, $catalog]) {
            $query = $invoice->{$relation}()->where('company_id', $invoice->company_id)->orderBy('id');
            if ($lock) {
                $query->lockForUpdate();
            }
            foreach ($query->get() as $source) {
                $items = $source->items()->with($catalog)->orderBy('id');
                if ($lock) {
                    $items->lockForUpdate();
                }
                foreach ($items->get() as $item) {
                    $key = $type.':'.$item->id;
                    $rows[$key] = [
                        'key' => $key,
                        'label' => ($type === 'requisition' ? 'Req. ' : 'OS ').$source->number.' — '.($item->{$catalog}?->name ?? $item->observations ?? '#'.$item->id),
                        'base' => (int) round((float) $item->total_amount * 100),
                        'gross' => (int) round((float) $item->gross_amount * 100),
                        'sale_discount' => (int) round((float) $item->discount_amount * 100),
                        'amount' => (int) round((float) $item->invoice_discount_amount * 100),
                        'share' => (float) $item->invoice_discount_share_percentage,
                        'model' => $item,
                    ];
                }
            }
        }

        return $rows;
    }

    public function signature(array $rows): string
    {
        return hash('sha256', json_encode(array_map(fn (array $row): array => [
            $row['key'], $row['base'], $row['gross'], $row['sale_discount'],
            (string) $row['model']->quantity, (string) $row['model']->unit_price,
            $row['model']->product_id, $row['model']->service_id, $row['model']->unit_of_measure,
        ], array_values($rows)), JSON_THROW_ON_ERROR));
    }

    /** Largest-remainder allocation avoids losing cents or favoring the last item. */
    public function allocate(int $amount, array $weights): array
    {
        $sum = array_sum($weights);
        if ($amount < 0 || ($amount > 0 && $sum <= 0) || count(array_filter($weights, fn ($weight) => ! is_numeric($weight) || ! is_finite((float) $weight) || $weight < 0))) {
            throw ValidationException::withMessages(['shares' => 'Informe participações válidas para distribuir o desconto.']);
        }
        $values = $remainders = [];
        foreach ($weights as $key => $weight) {
            $exact = $sum > 0 ? $amount * $weight / $sum : 0;
            $values[$key] = (int) floor($exact);
            $remainders[$key] = $exact - $values[$key];
        }
        arsort($remainders, SORT_NUMERIC);
        $remaining = $amount - array_sum($values);
        foreach (array_keys($remainders) as $key) {
            if ($remaining-- <= 0) {
                break;
            }
            $values[$key]++;
        }

        return $values;
    }

    public function apply(Invoice $invoice, float $amount, array $shares, ?string $reason, int $userId, ?string $expectedSignature = null): void
    {
        DB::transaction(function () use ($invoice, $amount, $shares, $reason, $userId, $expectedSignature): void {
            $record = Invoice::where('company_id', $invoice->company_id)->lockForUpdate()->findOrFail($invoice->id);
            Gate::forUser(User::findOrFail($userId))->authorize('update', $record);
            app(InvoiceActionEligibilityService::class)->ensureAllowed($record, InvoiceAction::EDIT_COMPOSITION);
            $rows = $this->rows($record, true);
            if ($expectedSignature !== null && $expectedSignature !== $this->signature($rows)) {
                throw ValidationException::withMessages(['shares' => 'A composição mudou durante a edição. Reabra o desconto e revise o rateio.']);
            }
            $cents = (int) round($amount * 100);
            if (! is_finite($amount) || $amount < 0 || $cents > array_sum(array_column($rows, 'base'))) {
                throw ValidationException::withMessages(['amount' => 'O desconto deve estar entre zero e a base dos itens, sem deslocamento.']);
            }
            if (count(array_filter($shares, fn ($share) => ! is_numeric($share) || ! is_finite((float) $share) || $share < 0 || $share > 100))) {
                throw ValidationException::withMessages(['shares' => 'As participações devem estar entre zero e 100%.']);
            }
            $shares = array_map(fn ($share): float => round((float) $share, 6), $shares);
            if ($cents > 0 && (array_diff(array_keys($shares), array_keys($rows)) || array_diff(array_keys($rows), array_keys($shares)) || (int) round(array_sum($shares) * 1000000) !== 100000000)) {
                throw ValidationException::withMessages(['shares' => 'Distribua exatamente 100% do desconto entre os itens atuais.']);
            }
            $allocated = $this->allocate($cents, $cents > 0 ? $shares : array_fill_keys(array_keys($rows), 0));
            $before = app(AuditRecorder::class)->snapshot($record);
            foreach ($rows as $key => $row) {
                if ($allocated[$key] > $row['base'] || ($cents > 0 && $row['base'] <= 0 && ($shares[$key] ?? 0) > 0)) {
                    throw ValidationException::withMessages(['shares' => 'O desconto de um item não pode exceder sua base líquida.']);
                }
                $row['model']->update([
                    'invoice_discount_amount' => $allocated[$key] / 100,
                    'invoice_discount_share_percentage' => $cents > 0 ? $shares[$key] : 0,
                ]);
            }
            $record->update([
                'invoice_discount_amount' => $cents / 100,
                'invoice_discount_reason' => $reason,
                'invoice_discount_signature' => $cents > 0 ? $this->signature($rows) : null,
                'updated_by' => $userId,
            ]);
            app(AuditRecorder::class)->recordModelEvent($record, 'invoice.discount_updated', 'Desconto de fechamento atualizado', $before,
                app(AuditRecorder::class)->snapshot($record->refresh()), $userId, metadata: ['shares' => $shares, 'reason' => $reason]);
        });
        $invoice->refresh();
    }

    public function ensureValid(Invoice $invoice): void
    {
        $rows = $this->rows($invoice, true);
        $cents = (int) round((float) $invoice->invoice_discount_amount * 100);
        if ($cents === 0 && array_sum(array_column($rows, 'amount')) === 0) {
            return;
        }
        if ($invoice->invoice_discount_signature !== $this->signature($rows)
            || $cents !== array_sum(array_column($rows, 'amount'))
            || (int) round(array_sum(array_column($rows, 'share')) * 1000000) !== 100000000) {
            throw ValidationException::withMessages(['invoice_discount_amount' => 'A composição mudou. Revise e aplique novamente o rateio do desconto antes de confirmar.']);
        }
        $expected = $this->allocate($cents, array_column($rows, 'share', 'key'));
        foreach ($rows as $key => $row) {
            if ($row['amount'] !== $expected[$key] || $row['amount'] > $row['base'] || $row['amount'] < 0) {
                throw ValidationException::withMessages(['invoice_discount_amount' => 'Revise a distribuição do desconto de fechamento.']);
            }
        }
    }
}
