<?php

namespace App\Services\Fiscal\Sefaz;

use App\Enum\Audit\AuditSource;
use App\Models\AccountPayable;
use App\Models\FiscalDocument;
use App\Models\SefazDistributionDocument;
use App\Services\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SefazDistributionFinancialLinkService
{
    public function synchronize(SefazDistributionDocument $document, ?AccountPayable $selected = null, ?FiscalDocument $note = null, ?int $actorId = null): ?AccountPayable
    {
        return DB::transaction(function () use ($document, $selected, $note, $actorId): ?AccountPayable {
            $dfe = SefazDistributionDocument::query()->lockForUpdate()->findOrFail($document->id);
            $before = app(AuditRecorder::class)->snapshot($dfe);
            $fiscal = $this->resolveFiscalDocument($dfe, $note);
            if ($fiscal && ! $dfe->partner_id) {
                $dfe->partner_id = $fiscal->customer_id;
            }

            if ($selected && $dfe->account_payable_id && (int) $dfe->account_payable_id !== (int) $selected->id) {
                $this->fail('O DF-e já possui outra conta a pagar vinculada.');
            }

            $payable = $selected ?? ($dfe->account_payable_id
                ? AccountPayable::query()->where('company_id', $dfe->company_id)->find($dfe->account_payable_id)
                : null);
            if ($dfe->account_payable_id && ! $payable) {
                $this->fail('A conta a pagar referenciada pelo DF-e não foi encontrada nesta empresa.');
            }

            if (! $payable && $fiscal) {
                $candidates = $fiscal->accountPayables()->where('company_id', $dfe->company_id)->limit(2)->get();
                if ($candidates->count() > 1) {
                    $this->fail('A nota possui mais de uma conta a pagar. Utilize a vinculação de conta existente para selecionar o lançamento.');
                }
                $payable = $candidates->first();
            }

            if ($payable) {
                $payable = AccountPayable::query()->lockForUpdate()->findOrFail($payable->id);
                if ((int) $payable->company_id !== (int) $dfe->company_id || (int) $payable->supplier_id !== (int) $dfe->partner_id) {
                    $this->fail('A conta a pagar deve pertencer à mesma empresa e ao fornecedor do DF-e.');
                }
                if (SefazDistributionDocument::query()->where('account_payable_id', $payable->id)->whereKeyNot($dfe->id)->exists()) {
                    $this->fail('A conta a pagar já está vinculada a outro DF-e.');
                }
                if ($payable->fiscal_document_id && (! $fiscal || (int) $payable->fiscal_document_id !== (int) $fiscal->id)) {
                    $this->fail('A conta a pagar já está vinculada a outra nota de entrada.');
                }
                if ($fiscal && ! $payable->fiscal_document_id) {
                    $payableBefore = app(AuditRecorder::class)->snapshot($payable);
                    $payable->update(['fiscal_document_id' => $fiscal->id]);
                    app(AuditRecorder::class)->recordModelEvent(
                        $payable, 'account_payable.fiscal_document_linked', 'Conta a pagar vinculada à nota de entrada pelo DF-e',
                        $payableBefore, app(AuditRecorder::class)->snapshot($payable), $actorId,
                        $actorId ? AuditSource::WEB : AuditSource::SYSTEM,
                    );
                }
                $dfe->account_payable_id = $payable->id;
            }

            if ($fiscal) {
                $dfe->fiscal_document_id = $fiscal->id;
                if ($payable && in_array($fiscal->financial_control_mode, [null, 'none'], true)) {
                    $fiscal->update(['financial_control_mode' => 'account_payable']);
                }
            }
            if ($dfe->isDirty()) {
                $dfe->save();
                app(AuditRecorder::class)->recordModelEvent(
                    $dfe, 'sefaz_distribution.financial_link_synchronized', 'Vínculos entre DF-e, nota e conta a pagar sincronizados',
                    $before, app(AuditRecorder::class)->snapshot($dfe), $actorId,
                    $actorId ? AuditSource::WEB : AuditSource::SYSTEM,
                );
            }

            return $payable;
        });
    }

    public function resolveFiscalDocument(SefazDistributionDocument $dfe, ?FiscalDocument $selected = null): ?FiscalDocument
    {
        $query = FiscalDocument::query()->where('company_id', $dfe->company_id)->lockForUpdate();
        $note = $selected || $dfe->fiscal_document_id
            ? $query->find($selected?->id ?? $dfe->fiscal_document_id)
            : $query->where('document_key', $dfe->document_key)->first();

        if (($selected || $dfe->fiscal_document_id) && ! $note) {
            $this->fail('A nota referenciada não foi encontrada nesta empresa.');
        }
        if ($note && ($note->document_key !== $dfe->document_key || $note->operation_type?->value !== '0')) {
            $this->fail('A nota de entrada deve possuir a mesma chave de acesso do DF-e.');
        }
        if ($selected && $dfe->fiscal_document_id && (int) $selected->id !== (int) $dfe->fiscal_document_id) {
            $this->fail('O DF-e já está vinculado a outra nota de entrada.');
        }

        return $note;
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['account_payable_id' => $message]);
    }
}
