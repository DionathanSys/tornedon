<?php

namespace App\Services\Invoice\Actions;

use App\Models\Invoice;
use App\Services\Audit\AuditRecorder;
use App\Services\Invoice\Validators\InvoiceValidator;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class CreateInvoiceAction
{
    use HandlesActionResponse;

    public function __construct(
        private int $createdBy,
    ) {}

    public function execute(array $data): ?Invoice
    {
        try {
            Log::debug('Iniciando criação de fatura', [
                'metodo'  => __METHOD__ . '@' . __LINE__,
                'user_id' => $this->createdBy,
                'data'    => $data,
            ]);

            $validated = InvoiceValidator::validateCreate($data);
            $validated['created_by'] = $this->createdBy;

            $invoice = Invoice::create($validated);
            $audit = app(AuditRecorder::class);

            $audit->recordModelEvent(
                $invoice,
                'invoice.created',
                "Fatura #{$invoice->invoice_number} criada",
                null,
                $audit->snapshot($invoice),
                $this->createdBy,
            );

            Log::info('Fatura criada com sucesso', [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'invoice_id' => $invoice->id,
                'number'     => $invoice->invoice_number,
            ]);

            $this->setSuccess();
            return $invoice;
        } catch (ValidationException $e) {
            $this->setError('Falha de validação dos dados', $e->errors());

            Log::error($this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'message'    => $this->getMessage(),
                'error_code' => $this->getErrorCode(),
                'errors'     => $e->errors(),
                'data'       => $data,
                'user_id'    => $this->createdBy,
            ]);

            return null;
        } catch (QueryException $e) {
            $this->setError('Erro ao salvar fatura no banco de dados');

            Log::error($this->getMessage(), [
                'metodo'        => __METHOD__ . '@' . __LINE__,
                'message'       => $this->getMessage(),
                'error_code'    => $this->getErrorCode(),
                'error_message' => $e->getMessage(),
                'data'          => $data,
                'user_id'       => $this->createdBy,
            ]);

            return null;
        } catch (\Exception $e) {
            $this->setError('Erro inesperado ao criar fatura');

            Log::error($this->getMessage(), [
                'metodo'        => __METHOD__ . '@' . __LINE__,
                'message'       => $this->getMessage(),
                'error_code'    => $this->getErrorCode(),
                'error_message' => $e->getMessage(),
                'trace'         => $e->getTraceAsString(),
                'data'          => $data,
                'user_id'       => $this->createdBy,
            ]);

            return null;
        }
    }
}
