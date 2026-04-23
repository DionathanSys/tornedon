<?php

namespace App\Services\Invoice\Actions;

use App\Models\Invoice;
use App\Services\Audit\AuditRecorder;
use App\Services\Invoice\Validators\InvoiceValidator;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class UpdateInvoiceAction
{
    use HandlesActionResponse;

    public function __construct(
        private int     $updatedBy,
        private Invoice $invoice,
    ) {}

    public function execute(array $data): ?Invoice
    {
        try {
            $audit = app(AuditRecorder::class);
            $before = $audit->snapshot($this->invoice);

            Log::debug('Iniciando atualização de fatura', [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'invoice_id' => $this->invoice->id,
                'user_id'    => $this->updatedBy,
                'data'       => $data,
            ]);

            if ($this->invoice->discount_amount < $this->invoice->total_amount) {
                $this->setError('O desconto não pode ser maior que o valor total da fatura');

                Log::error($this->getMessage(), [
                    'metodo'          => __METHOD__ . '@' . __LINE__,
                    'message'         => $this->getMessage(),
                    'error_code'      => $this->getErrorCode(),
                    'invoice_id'      => $this->invoice->id,
                    'data'            => $data,
                    'discount_amount' => $this->invoice->discount_amount,
                    'total_amount'    => $this->invoice->total_amount,
                    'user_id'         => $this->updatedBy,
                ]);

                return null;
            }

            $validated = InvoiceValidator::validateUpdate($data, $this->invoice->id);

            unset($validated['company_id']);
            $validated['updated_by'] = $this->updatedBy;

            $this->invoice->update($validated);
            $this->invoice->refresh();

            $audit->recordModelEvent(
                $this->invoice,
                'invoice.updated',
                "Fatura #{$this->invoice->invoice_number} atualizada",
                $before,
                $audit->snapshot($this->invoice),
                $this->updatedBy,
            );

            Log::info('Fatura atualizada com sucesso', [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'invoice_id' => $this->invoice->id,
                'user_id'    => $this->updatedBy,
            ]);

            $this->setSuccess();
            return $this->invoice;
        } catch (ValidationException $e) {
            $this->setError('Falha de validação dos dados', $e->errors());

            Log::error($this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'message'    => $this->getMessage(),
                'error_code' => $this->getErrorCode(),
                'invoice_id' => $this->invoice->id,
                'errors'     => $e->errors(),
                'data'       => $data,
                'user_id'    => $this->updatedBy,
            ]);

            return null;
        } catch (QueryException $e) {
            $this->setError('Erro ao atualizar fatura no banco de dados');

            Log::error($this->getMessage(), [
                'metodo'        => __METHOD__ . '@' . __LINE__,
                'message'       => $this->getMessage(),
                'error_code'    => $this->getErrorCode(),
                'invoice_id'    => $this->invoice->id,
                'error_message' => $e->getMessage(),
                'data'          => $data,
                'user_id'       => $this->updatedBy,
            ]);

            return null;
        } catch (\Exception $e) {
            $this->setError('Erro inesperado ao atualizar fatura');

            Log::error($this->getMessage(), [
                'metodo'        => __METHOD__ . '@' . __LINE__,
                'message'       => $this->getMessage(),
                'error_code'    => $this->getErrorCode(),
                'invoice_id'    => $this->invoice->id,
                'error_message' => $e->getMessage(),
                'trace'         => $e->getTraceAsString(),
                'data'          => $data,
                'user_id'       => $this->updatedBy,
            ]);

            return null;
        }
    }
}
