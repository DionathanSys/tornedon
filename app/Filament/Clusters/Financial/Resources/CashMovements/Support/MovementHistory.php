<?php

namespace App\Filament\Clusters\Financial\Resources\CashMovements\Support;

use App\Models\AccountPayable;
use App\Models\AccountPayableInstallmentPayment;
use App\Models\AccountReceivable;
use App\Models\AccountReceivableInstallmentPayment;
use App\Models\CashMovement;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

final class MovementHistory
{
    public function entries(CashMovement $movement): array
    {
        if (! $this->canView($movement)) {
            return [];
        }

        $entries = [];
        $source = $movement;
        $visited = [$movement->id => true];

        // Estornos não carregam necessariamente origin_type/origin_id.
        while ($source->reversal_of_id) {
            $previous = $source->reversalOf;
            if (! $this->canView($previous) || isset($visited[$previous->id])) {
                $entries[] = $this->unavailable('Movimento anterior');
                break;
            }
            $visited[$previous->id] = true;
            $entries[] = $this->entry('Movimento anterior', $previous);
            $source = $previous;
        }

        $this->appendOrigin($entries, $source);

        foreach ($movement->reversals()->where('company_id', $movement->company_id)->orderBy('id')->get() as $reversal) {
            if ($this->canView($reversal)) {
                $entries[] = $this->entry('Estorno', $reversal);
            }
        }

        if ($counterpart = $source->transferCounterpart()) {
            $entries[] = $this->canView($counterpart)
                ? $this->entry('Outra conta da transferência', $counterpart)
                : $this->unavailable('Outra conta da transferência');
        }

        return $entries;
    }

    private function appendOrigin(array &$entries, CashMovement $movement): void
    {
        $payable = $movement->origin_type === AccountPayableInstallmentPayment::class;
        if (! $payable && $movement->origin_type !== AccountReceivableInstallmentPayment::class) {
            $entries[] = ['label' => $movement->origin_type === 'manual' ? 'Lançamento manual' : 'Origem',
                'description' => $movement->origin_type === 'manual' ? 'Criado diretamente no extrato financeiro.' : 'Sem registro de origem disponível.',
                'date' => null, 'amount' => null, 'url' => null];

            return;
        }

        // As duas relações usam origin_id: escolher pelo tipo evita colisões de IDs.
        $payment = $payable ? $movement->payablePayment : $movement->receivablePayment;
        if (! $this->canView($payment)) {
            $entries[] = $this->unavailable($payable ? 'Conta a pagar de origem' : 'Conta a receber de origem');

            return;
        }

        $installment = $payment->installment;
        if (! $this->canView($installment)) {
            $entries[] = $this->entry($payable ? 'Pagamento' : 'Recebimento', $payment);
            $entries[] = $this->unavailable('Parcela de origem');

            return;
        }

        $title = $payable ? $installment->accountPayable : $installment->accountReceivable;
        $title = $this->canView($title) ? $title : null;
        $entries[] = $this->entry($payable ? 'Pagamento' : 'Recebimento', $payment, $title);
        $entries[] = $this->entry('Parcela '.$installment->sequence_number, $installment, $title);
        if (! $title) {
            $entries[] = $this->unavailable($payable ? 'Conta a pagar' : 'Conta a receber');

            return;
        }

        $entries[] = $this->entry($payable ? 'Conta a pagar' : 'Conta a receber', $title);
        $this->appendDocument($entries, 'Documento fiscal', $title->fiscalDocument);
        if ($title instanceof AccountPayable) {
            $this->appendDocument($entries, 'Fechamento de compras', $title->purchaseClosing);
            foreach ($title->companyCardStatements as $statement) {
                $this->appendDocument($entries, 'Fatura de cartão', $statement);
            }
        } elseif ($title instanceof AccountReceivable) {
            $invoices = $title->invoiceLinks()->with('invoice')->get()->pluck('invoice');
            if ($title->invoice) {
                $invoices->push($title->invoice);
            }
            foreach ($invoices->filter()->unique('id') as $invoice) {
                $this->appendDocument($entries, 'Fatura', $invoice);
            }
        }
    }

    private function appendDocument(array &$entries, string $label, ?Model $record): void
    {
        if ($this->canView($record)) {
            $entries[] = $this->entry($label, $record);
        }
    }

    private function canView(?Model $record): bool
    {
        return $record && Filament::getTenant()
            && (int) $record->company_id === (int) Filament::getTenant()->id
            && Gate::allows('view', $record);
    }

    private function entry(string $label, Model $record, ?Model $fallback = null): array
    {
        $attributes = $record->getAttributes();
        $number = $attributes['document_number'] ?? $attributes['invoice_number'] ?? $attributes['number'] ?? null;
        $description = collect(['#'.$record->getKey(), $number, $attributes['description'] ?? null])->filter()->implode(' · ');
        $date = $attributes['transaction_date'] ?? $attributes['payment_date'] ?? $attributes['due_date'] ?? $attributes['invoice_date'] ?? $attributes['created_at'] ?? null;

        return [
            'label' => $label,
            'description' => $description,
            'date' => $date,
            'amount' => $record->amount ?? $record->due_amount,
            'url' => $this->url($record) ?? ($fallback ? $this->url($fallback) : null),
        ];
    }

    private function unavailable(string $label): array
    {
        return ['label' => $label, 'description' => 'Registro indisponível ou sem permissão de acesso.', 'date' => null, 'amount' => null, 'url' => null];
    }

    private function url(Model $record): ?string
    {
        foreach (Filament::getCurrentPanel()->getResources() as $resource) {
            if ($resource::getModel() !== $record::class || ! $resource::canAccess() || ! $resource::canView($record)) {
                continue;
            }
            $page = $resource::hasPage('view') ? 'view' : ($resource::hasPage('edit') && $resource::canEdit($record) ? 'edit' : null);
            // Um modelo pode ter resources distintos (por exemplo, NF de entrada e saída).
            if ($page && $resource::getEloquentQuery()->whereKey($record->getKey())->exists()) {
                return $resource::getUrl($page, ['record' => $record], panel: Filament::getCurrentPanel()->getId(), tenant: Filament::getTenant());
            }
        }

        return null;
    }
}
