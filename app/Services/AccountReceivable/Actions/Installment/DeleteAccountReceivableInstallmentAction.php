<?php

namespace App\Services\AccountReceivable\Actions\Installment;

use App\Models\AccountReceivableInstallment;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

class DeleteAccountReceivableInstallmentAction
{
    use HandlesActionResponse;

    public function __construct(private readonly AccountReceivableInstallment $installment) {}

    public function execute(): bool
    {
        try {
            if ($this->installment->payments()->exists()) {
                $this->setError('Nao e possivel excluir uma parcela com recebimentos registrados.');
                return false;
            }

            $result = $this->installment->delete();

            $this->setSuccess();

            return $result;
        } catch (QueryException $e) {
            $this->setError('Erro ao excluir parcela de conta a receber.');

            Log::error($this->getMessage(), [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'error_code' => $this->getErrorCode(),
                'error_message' => $e->getMessage(),
                'installment_id' => $this->installment->id,
            ]);

            return false;
        } catch (\Exception $e) {
            $this->setError('Erro inesperado ao excluir parcela de conta a receber.');

            Log::error($this->getMessage(), [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'error_code' => $this->getErrorCode(),
                'error_message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'installment_id' => $this->installment->id,
            ]);

            return false;
        }
    }
}
