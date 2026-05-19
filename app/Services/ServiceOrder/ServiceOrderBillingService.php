<?php

namespace App\Services\ServiceOrder;

use App\Enum\ServiceOrder\State;
use App\Exceptions\DomainValidationException;
use App\Models\Invoice;
use App\Models\ServiceOrder;
use App\Services\Shared\CommercialInvoiceOrchestrationService;
use App\Services\Shared\CommercialInvoiceValidationService;
use App\Traits\HandlesServiceResponse;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

class ServiceOrderBillingService
{
    use HandlesServiceResponse;

    public function invoice(ServiceOrder|Collection $records, int $userId): ?Invoice
    {
        $this->resetResponse();

        $records = $records instanceof ServiceOrder ? new Collection([$records]) : $records;

        $validationError = app(CommercialInvoiceValidationService::class)->validateForNewInvoice(
            $records,
            'Todos os registros selecionados devem pertencer ao mesmo cliente.',
            'Apenas ordens de serviço com status "Encerrada" podem ser faturadas.',
            fn (ServiceOrder $record): bool => $record->status === State::CLOSED,
            fn (ServiceOrder $record): string => "A OS #{$record->number} não possui itens.",
        );

        if ($validationError !== null) {
            $this->setError($validationError);
            return null;
        }

        try {
            $first = $records->first();
            $orchestrator = app(CommercialInvoiceOrchestrationService::class);
            $invoice = $orchestrator->createAndAttach(
                $records,
                $userId,
                [
                    'payment_method' => $first->payment_method?->value,
                    'payment_condition' => $first->payment_condition?->value,
                ],
                function (Collection $records, Invoice $invoice, int $userId): void {
                    foreach ($records as $record) {
                        $record->state()->invoice($record, $userId, $invoice->id);
                    }
                },
                'Ordem(ns) de serviço faturada(s) com sucesso'
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

            Log::info('ServiceOrderBillingService: OS(s) faturada(s) com sucesso', [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'service_order_ids' => $records->pluck('id')->all(),
                'invoice_id' => $invoice->id,
            ]);

            $this->setSuccess('Ordem(ns) de serviço faturada(s) com sucesso');

            return $invoice;
        } catch (DomainValidationException $e) {
            $this->setError('Transição inválida', $e->errors);

            Log::warning('ServiceOrderBillingService: Transição inválida ao faturar OS', [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'service_order_ids' => $records->pluck('id')->all(),
                'errors' => $e->errors,
            ]);

            return null;
        } catch (QueryException $e) {
            $this->setError('Erro ao faturar OS no banco de dados');

            Log::error('ServiceOrderBillingService: QueryException ao faturar OS', [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'service_order_ids' => $records->pluck('id')->all(),
                'exception' => $e->getMessage(),
            ]);

            return null;
        } catch (\Exception $e) {
            if (! $this->hasError()) {
                $this->setError($e->getMessage() ?: 'Erro ao faturar ordem de serviço');
            }

            Log::error('ServiceOrderBillingService: Erro ao faturar OS', [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'service_order_ids' => $records->pluck('id')->all(),
                'error_code' => $this->getErrorCode(),
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return null;
        }
    }
}
