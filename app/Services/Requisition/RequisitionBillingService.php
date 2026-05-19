<?php

namespace App\Services\Requisition;

use App\Enum\Requisition\Status;
use App\Exceptions\DomainValidationException;
use App\Models\Invoice;
use App\Models\Requisition;
use App\Services\Shared\CommercialInvoiceOrchestrationService;
use App\Services\Shared\CommercialInvoiceValidationService;
use App\Traits\HandlesServiceResponse;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

class RequisitionBillingService
{
    use HandlesServiceResponse;

    public function invoice(Requisition|Collection $records, int $userId, callable $attachRecords): ?Invoice
    {
        $this->resetResponse();

        $records = $records instanceof Requisition ? new Collection([$records]) : $records;

        $validationError = app(CommercialInvoiceValidationService::class)->validateForNewInvoice(
            $records,
            'Todos os registros selecionados devem pertencer ao mesmo cliente.',
            'Apenas requisições com status "Encerrada" podem ser faturadas.',
            fn (Requisition $record): bool => $record->status === Status::CLOSED,
            fn (Requisition $record): string => "A requisição #{$record->number} não possui itens.",
        );

        if ($validationError !== null) {
            $this->setError($validationError);
            return null;
        }

        try {
            $orchestrator = app(CommercialInvoiceOrchestrationService::class);
            $invoice = $orchestrator->createAndAttach(
                $records,
                $userId,
                [],
                $attachRecords,
                'Requisição(ões) faturada(s) com sucesso'
            );

            if ($invoice === null) {
                $this->setError(
                    $orchestrator->getMessage(),
                    $orchestrator->getErrors(),
                    $orchestrator->getStatus(),
                    $orchestrator->getErrorCode(),
                );

                throw new \RuntimeException($this->getMessage());
            }

            Log::info('RequisitionBillingService: Requisição(ões) faturada(s) com sucesso', [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'requisition_ids' => $records->pluck('id')->all(),
                'invoice_id' => $invoice->id,
            ]);

            $this->setSuccess('Requisição(ões) faturada(s) com sucesso');

            return $invoice;
        } catch (DomainValidationException $e) {
            $this->setError('Transição inválida', $e->errors);

            Log::warning('RequisitionBillingService: Transição inválida ao faturar', [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'requisition_ids' => $records->pluck('id')->all(),
                'errors' => $e->errors,
            ]);

            return null;
        } catch (QueryException $e) {
            $this->setError('Erro ao faturar requisição no banco de dados');

            Log::error('RequisitionBillingService: QueryException ao faturar', [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'requisition_ids' => $records->pluck('id')->all(),
                'exception' => $e->getMessage(),
            ]);

            return null;
        } catch (\Exception $e) {
            if (! $this->hasError()) {
                $this->setError($e->getMessage() ?: 'Erro ao faturar requisição');
            }

            Log::error('RequisitionBillingService: Erro ao faturar', [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'requisition_ids' => $records->pluck('id')->all(),
                'error_code' => $this->getErrorCode(),
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return null;
        }
    }

    public function invoiceIntoExisting(Requisition|Collection $records, int $userId, Invoice $invoice, callable $attachRecords): ?Invoice
    {
        $this->resetResponse();

        $records = $records instanceof Requisition ? new Collection([$records]) : $records;

        $validationError = app(CommercialInvoiceValidationService::class)->validateForExistingInvoice(
            $records,
            $invoice,
            'A requisição deve pertencer ao mesmo cliente da fatura da ordem de serviço.',
            'A requisição deve pertencer à mesma empresa da fatura da ordem de serviço.',
            'Apenas requisições com status "Encerrada" podem ser faturadas na mesma fatura da ordem de serviço.',
            fn (Requisition $record): bool => $record->status === Status::CLOSED,
            fn (Requisition $record): string => "A requisição #{$record->number} já está vinculada a outra fatura.",
            fn (Requisition $record): string => "A requisição #{$record->number} não possui itens.",
        );

        if ($validationError !== null) {
            $this->setError($validationError);
            return null;
        }

        try {
            $orchestrator = app(CommercialInvoiceOrchestrationService::class);
            $updatedInvoice = $orchestrator->attachToExisting(
                $records,
                $invoice,
                $userId,
                $attachRecords,
                'Requisição(ões) faturada(s) com sucesso na mesma fatura da ordem de serviço.'
            );

            if ($updatedInvoice === null) {
                $this->setError(
                    $orchestrator->getMessage(),
                    $orchestrator->getErrors(),
                    $orchestrator->getStatus(),
                    $orchestrator->getErrorCode(),
                );

                throw new \RuntimeException($this->getMessage());
            }

            Log::info('RequisitionBillingService: Requisição(ões) faturada(s) em fatura existente', [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'requisition_ids' => $records->pluck('id')->all(),
                'invoice_id' => $invoice->id,
            ]);

            $this->setSuccess('Requisição(ões) faturada(s) com sucesso na mesma fatura da ordem de serviço.');

            return $updatedInvoice;
        } catch (DomainValidationException $e) {
            $this->setError('Transição inválida', $e->errors);

            Log::warning('RequisitionBillingService: Transição inválida ao faturar em fatura existente', [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'requisition_ids' => $records->pluck('id')->all(),
                'invoice_id' => $invoice->id,
                'errors' => $e->errors,
            ]);

            return null;
        } catch (QueryException $e) {
            $this->setError('Erro ao faturar requisição na fatura da ordem de serviço.');

            Log::error('RequisitionBillingService: QueryException ao faturar em fatura existente', [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'requisition_ids' => $records->pluck('id')->all(),
                'invoice_id' => $invoice->id,
                'exception' => $e->getMessage(),
            ]);

            return null;
        } catch (\Exception $e) {
            if (! $this->hasError()) {
                $this->setError('Erro ao faturar requisição na fatura da ordem de serviço.');
            }

            Log::error('RequisitionBillingService: Erro ao faturar em fatura existente', [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'requisition_ids' => $records->pluck('id')->all(),
                'invoice_id' => $invoice->id,
                'error_code' => $this->getErrorCode(),
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return null;
        }
    }
}
