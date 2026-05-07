<?php

namespace App\Services\Invoice\Actions;

use App\Enum\Invoice\Status as InvoiceStatus;
use App\Models\Invoice;
use App\Services\AccountReceivable\AccountReceivableService;
use App\Services\Audit\AuditRecorder;
use App\Services\FiscalDocument\FiscalDocumentService;
use App\Traits\HandlesActionResponse;
use Illuminate\Support\Facades\Log;

class ReturnInvoiceToPendingAction
{
    use HandlesActionResponse;

    public function __construct(
        private Invoice $invoice,
        private int $userId,
    ) {}

    public function execute(): bool
    {
        $this->invoice->loadMissing([
            'fiscalDocuments.accountPayables',
            'accountReceivables.installments.payments',
        ]);

        if (! $this->validateCanReturnToPending()) {
            return false;
        }

        $audit = app(AuditRecorder::class);
        $before = $audit->snapshot($this->invoice);
        $fiscalDocumentService = app(FiscalDocumentService::class);
        $accountReceivableService = app(AccountReceivableService::class);

        foreach ($this->invoice->accountReceivables as $accountReceivable) {
            $deleted = $accountReceivableService->delete($accountReceivable);

            if (! $deleted || $accountReceivableService->hasError()) {
                $this->setError(
                    $accountReceivableService->getMessage(),
                    $accountReceivableService->getErrors(),
                    $accountReceivableService->getErrorCode(),
                );

                return false;
            }
        }

        foreach ($this->invoice->fiscalDocuments as $fiscalDocument) {
            $deleted = $fiscalDocumentService->delete($fiscalDocument);

            if (! $deleted || $fiscalDocumentService->hasError()) {
                $this->setError(
                    $fiscalDocumentService->getMessage(),
                    $fiscalDocumentService->getErrors(),
                    $fiscalDocumentService->getErrorCode(),
                );

                return false;
            }
        }

        $this->invoice->update([
            'status' => InvoiceStatus::PENDING->value,
            'pending' => true,
            'confirmed' => false,
            'confirmed_at' => null,
            'confirmed_by' => null,
            'updated_by' => $this->userId,
        ]);

        $this->invoice->refresh();

        $audit->recordModelEvent(
            $this->invoice,
            'invoice.returned_to_pending',
            "Fatura #{$this->invoice->invoice_number} retornada para pendente",
            $before,
            $audit->snapshot($this->invoice),
            $this->userId,
        );

        Log::info('Invoice retornada para pendente com sucesso', [
            'metodo' => __METHOD__ . '@' . __LINE__,
            'invoice_id' => $this->invoice->id,
            'user_id' => $this->userId,
        ]);

        $this->setSuccess('Fatura retornada para pendente com sucesso.');

        return true;
    }

    private function validateCanReturnToPending(): bool
    {
        if ($this->invoice->canceled || $this->invoice->status === InvoiceStatus::CANCELLED) {
            $this->setError('Nao e possivel retornar uma fatura cancelada para pendente.');
            return false;
        }

        if ($this->invoice->status !== InvoiceStatus::CONFIRMED || ! $this->invoice->confirmed) {
            $this->setError('Somente faturas confirmadas podem retornar para pendente.');
            return false;
        }

        foreach ($this->invoice->fiscalDocuments as $fiscalDocument) {
            if (
                $fiscalDocument->isNfeInProcessing()
                || $fiscalDocument->isNfseInProcessing()
                || $fiscalDocument->isNfeAuthorized()
                || $fiscalDocument->isNfseAuthorized()
                || $fiscalDocument->isNfeCanceled()
                || $fiscalDocument->isNfseCanceled()
                || $fiscalDocument->isNfeSent()
                || $fiscalDocument->isNfseSent()
            ) {
                $this->setError('A fatura possui documento fiscal com comunicacao fiscal iniciada. Exclua ou cancele o documento antes de retornar para pendente.');
                return false;
            }
        }

        foreach ($this->invoice->accountReceivables as $accountReceivable) {
            if ($accountReceivable->paid || $accountReceivable->payments()->exists()) {
                $this->setError('A fatura possui contas a receber com recebimentos registrados.');
                return false;
            }
        }

        return true;
    }
}
