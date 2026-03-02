<?php

namespace App\Services\AccountPayable\Actions;

use App\Models\AccountPayable;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

class DeleteAccountPayableAction
{
    use HandlesActionResponse;

    public function __construct(
        private AccountPayable $accountPayable,
    ) {}

    public function execute(): bool
    {
        try {
            Log::debug('Iniciando exclusão de conta a pagar', [
                'metodo'             => __METHOD__ . '@' . __LINE__,
                'account_payable_id' => $this->accountPayable->id,
            ]);

            if (!$this->validateCanDelete()) {
                return false;
            }

            $result = $this->accountPayable->delete();

            Log::info('Conta a pagar excluída com sucesso', [
                'metodo'             => __METHOD__ . '@' . __LINE__,
                'account_payable_id' => $this->accountPayable->id,
            ]);

            $this->setSuccess();
            return $result;
        } catch (QueryException $e) {
            $this->setError('Erro ao excluir conta a pagar. Ela pode estar vinculada a outros registros.');

            Log::error($this->getMessage(), [
                'metodo'             => __METHOD__ . '@' . __LINE__,
                'message'            => $this->getMessage(),
                'error_code'         => $this->getErrorCode(),
                'account_payable_id' => $this->accountPayable->id,
                'error_message'      => $e->getMessage(),
            ]);

            return false;
        } catch (\Exception $e) {
            $this->setError('Erro inesperado ao excluir conta a pagar');

            Log::error($this->getMessage(), [
                'metodo'             => __METHOD__ . '@' . __LINE__,
                'message'            => $this->getMessage(),
                'error_code'         => $this->getErrorCode(),
                'account_payable_id' => $this->accountPayable->id,
                'error_message'      => $e->getMessage(),
                'trace'              => $e->getTraceAsString(),
            ]);

            return false;
        }
    }

    private function validateCanDelete(): bool
    {
        if ($this->accountPayable->paid) {
            $this->setError('Não é possível excluir uma conta que já foi paga');

            Log::warning($this->getMessage(), [
                'metodo'             => __METHOD__ . '@' . __LINE__,
                'message'            => $this->getMessage(),
                'error_code'         => $this->getErrorCode(),
                'account_payable_id' => $this->accountPayable->id,
            ]);

            return false;
        }

        return true;
    }
}
