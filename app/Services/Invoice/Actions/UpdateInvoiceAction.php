<?php

namespace App\Services\Invoice\Actions;

use App\Models\Invoice;
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
            Log::debug('Iniciando atualização de fatura', [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'invoice_id' => $this->invoice->id,
                'user_id'    => $this->updatedBy,
                'data'       => $data,
            ]);

            $validated = InvoiceValidator::validateUpdate($data, $this->invoice->id);

            unset($validated['company_id']);
            $validated['updated_by'] = $this->updatedBy;

            $this->invoice->update($validated);

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
