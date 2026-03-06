<?php

namespace App\Services\Invoice;

use App\Enum\Invoice\Status;
use App\Models\Invoice;
use App\Models\InvoiceSequence;
use App\Services\Invoice\Actions\CreateInvoiceAction;
use App\Services\Invoice\Actions\DeleteInvoiceAction;
use App\Services\Invoice\Actions\UpdateInvoiceAction;
use App\Traits\HandlesServiceResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class InvoiceService
{
    use HandlesServiceResponse;

    /* ==============================
     |  Operações de Escrita
     |==============================*/

    public function create(array $data, int $createdBy): ?Invoice
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($data, $createdBy) {
                $data['status'] = $data['status'] ?? Status::PENDING->value;

                $action = new CreateInvoiceAction($createdBy);
                $invoice = $action->execute($data);

                if ($action->hasError()) {
                    $this->setError(
                        $action->getMessage(),
                        $action->getErrors(),
                        422,
                        $action->getErrorCode()
                    );

                    Log::error($this->getMessage(), [
                        'metodo'         => __METHOD__ . '@' . __LINE__,
                        'message'        => $this->getMessage(),
                        'error_code'     => $this->getErrorCode(),
                        'action_message' => $action->getMessage(),
                        'errors'         => $action->getErrors(),
                        'data'           => $data,
                        'user_id'        => $createdBy,
                    ]);

                    return null;
                }

                $this->setSuccess('Fatura criada com sucesso');

                Log::info('Fatura criada com sucesso via service', [
                    'metodo'     => __METHOD__ . '@' . __LINE__,
                    'invoice_id' => $invoice->id,
                    'number'     => $invoice->invoice_number,
                ]);

                return $invoice;
            });
        } catch (\Exception $e) {
            $this->setError('Erro ao criar fatura');

            Log::error($this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'error_code' => $this->getErrorCode(),
                'message'    => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
                'data'       => $data,
                'user_id'    => $createdBy,
            ]);

            return null;
        }
    }

    public function update(Invoice $invoice, array $data, int $updatedBy): ?Invoice
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($invoice, $data, $updatedBy) {
                $action = new UpdateInvoiceAction($updatedBy, $invoice);
                $updated = $action->execute($data);

                if ($action->hasError()) {
                    $this->setError(
                        $action->getMessage(),
                        $action->getErrors(),
                        422,
                        $action->getErrorCode()
                    );

                    Log::error($this->getMessage(), [
                        'metodo'     => __METHOD__ . '@' . __LINE__,
                        'invoice_id' => $invoice->id,
                        'message'    => $this->getMessage(),
                        'error_code' => $this->getErrorCode(),
                        'errors'     => $action->getErrors(),
                        'data'       => $data,
                        'user_id'    => $updatedBy,
                    ]);

                    return null;
                }

                $this->setSuccess('Fatura atualizada com sucesso');

                Log::info('Fatura atualizada com sucesso via service', [
                    'metodo'     => __METHOD__ . '@' . __LINE__,
                    'invoice_id' => $invoice->id,
                ]);

                return $updated;
            });
        } catch (\Exception $e) {
            $this->setError('Erro ao atualizar fatura');

            Log::error($this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'invoice_id' => $invoice->id,
                'error_code' => $this->getErrorCode(),
                'message'    => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
                'data'       => $data,
                'user_id'    => $updatedBy,
            ]);

            return null;
        }
    }

    public function delete(Invoice $invoice): bool
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($invoice) {
                $action = new DeleteInvoiceAction($invoice);
                $result = $action->execute();

                if ($action->hasError()) {
                    $this->setError(
                        $action->getMessage(),
                        $action->getErrors(),
                        422,
                        $action->getErrorCode()
                    );

                    Log::error($this->getMessage(), [
                        'metodo'     => __METHOD__ . '@' . __LINE__,
                        'invoice_id' => $invoice->id,
                        'message'    => $action->getMessage(),
                        'error_code' => $action->getErrorCode(),
                        'errors'     => $action->getErrors(),
                    ]);

                    return false;
                }

                $this->setSuccess('Fatura excluída com sucesso');

                Log::info('Fatura excluída com sucesso via service', [
                    'metodo'     => __METHOD__ . '@' . __LINE__,
                    'invoice_id' => $invoice->id,
                ]);

                return $result;
            });
        } catch (\Exception $e) {
            $this->setError('Erro ao excluir fatura');

            Log::error($this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'invoice_id' => $invoice->id,
                'error_code' => $this->getErrorCode(),
                'message'    => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
            ]);

            return false;
        }
    }

    /* ==============================
     |  Métodos Auxiliares
     |==============================*/

    /**
     * Gera o próximo número de fatura para a empresa (lock pessimista).
     */
    public function generateNumber(int $companyId): string
    {
        $sequence = InvoiceSequence::lockForUpdate()
            ->firstOrCreate(
                ['company_id' => $companyId],
                ['last_number' => 0]
            );

        $sequence->increment('last_number');

        return str_pad($sequence->last_number, 6, '0', STR_PAD_LEFT);
    }
}
