<?php

namespace App\Services\Invoice\Actions;

use App\Models\Invoice;
use App\Models\User;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

class DeleteInvoiceAction
{
    use HandlesActionResponse;

    public function __construct(
        private Invoice $invoice,
        private int $deletedBy,
    ) {}

    public function execute(): bool
    {
        try {
            Log::debug('Iniciando exclusão de fatura', [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'invoice_id' => $this->invoice->id,
                'number'     => $this->invoice->invoice_number,
                'user_id'    => $this->deletedBy,
            ]);

            if (! $this->validateCanDelete()) {
                return false;
            }

            $result = $this->invoice->delete();

            Log::info('Fatura excluída com sucesso', [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'invoice_id' => $this->invoice->id,
                'number'     => $this->invoice->invoice_number,
                'user_id'    => $this->deletedBy,
            ]);

            $this->setSuccess();

            return $result;
        } catch (QueryException $e) {
            $this->setError('Erro ao excluir fatura. Ela pode estar vinculada a outros registros.');

            Log::error($this->getMessage(), [
                'metodo'        => __METHOD__ . '@' . __LINE__,
                'message'       => $this->getMessage(),
                'error_code'    => $this->getErrorCode(),
                'invoice_id'    => $this->invoice->id,
                'error_message' => $e->getMessage(),
                'user_id'       => $this->deletedBy,
            ]);

            return false;
        } catch (\Exception $e) {
            $this->setError('Erro inesperado ao excluir fatura');

            Log::error($this->getMessage(), [
                'metodo'        => __METHOD__ . '@' . __LINE__,
                'message'       => $this->getMessage(),
                'error_code'    => $this->getErrorCode(),
                'invoice_id'    => $this->invoice->id,
                'error_message' => $e->getMessage(),
                'trace'         => $e->getTraceAsString(),
                'user_id'       => $this->deletedBy,
            ]);

            return false;
        }
    }

    private function validateCanDelete(): bool
    {
        $user = User::query()->find($this->deletedBy);

        if (! $user || ! $user->belongsToCompany($this->invoice->company_id)) {
            $this->setError('Não é possível excluir fatura de outra empresa');

            Log::warning($this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'message'    => $this->getMessage(),
                'error_code' => $this->getErrorCode(),
                'invoice_id' => $this->invoice->id,
                'company_id' => $this->invoice->company_id,
                'user_id'    => $this->deletedBy,
            ]);

            return false;
        }

        if ($this->invoice->fiscalDocuments()->exists()) {
            $this->setError('Não é possível excluir fatura que já gerou documento fiscal');

            Log::warning($this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'message'    => $this->getMessage(),
                'error_code' => $this->getErrorCode(),
                'invoice_id' => $this->invoice->id,
                'user_id'    => $this->deletedBy,
            ]);

            return false;
        }

        if ($this->invoice->confirmed) {
            $this->setError('Não é possível excluir uma fatura confirmada');

            Log::warning($this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'message'    => $this->getMessage(),
                'error_code' => $this->getErrorCode(),
                'invoice_id' => $this->invoice->id,
                'user_id'    => $this->deletedBy,
            ]);

            return false;
        }

        if ($this->invoice->accountReceivables()->exists()) {
            $this->setError('Não é possível excluir fatura que possui contas a receber vinculadas');

            Log::warning($this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'message'    => $this->getMessage(),
                'error_code' => $this->getErrorCode(),
                'invoice_id' => $this->invoice->id,
                'user_id'    => $this->deletedBy,
            ]);

            return false;
        }

        return true;
    }
}
