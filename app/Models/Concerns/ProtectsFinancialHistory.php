<?php

namespace App\Models\Concerns;

use App\Support\Financial\InstallmentPaymentGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

trait ProtectsFinancialHistory
{
    /** Protect direct model/Filament deletions as well as service calls. */
    public function delete(): ?bool
    {
        return DB::transaction(function () {
            if (! $this->exists) {
                return null;
            }

            if (method_exists($this, 'installments')) {
                $record = $this->newQuery()->lockForUpdate()->findOrFail($this->getKey());
                $record->installments()->orderBy('id')->lockForUpdate()->get();
            } else {
                $record = InstallmentPaymentGuard::lock($this);
            }

            if ($record->paid || (float) $record->paid_amount > 0 || (float) $record->received_amount > 0 || $record->payments()->exists()) {
                throw ValidationException::withMessages([
                    'payments' => ['Não é possível excluir registros com pagamentos. Utilize o fluxo de estorno ou compensação.'],
                ]);
            }

            return parent::delete();
        });
    }
}
