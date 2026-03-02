<?php

namespace App\Services\AccountReceivable\Actions;

use App\Models\AccountReceivable;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

class DeleteAccountReceivableAction
{
    use HandlesActionResponse;

    public function __construct(
        private AccountReceivable $accountReceivable,
    ) {}

    public function execute(): bool
    {
        try {
            Log::debug('Iniciando exclusão de conta a receber', [
                'metodo'                => __METHOD__ . '@' . __LINE__,
                'account_receivable_id' => $this->accountReceivable->id,
            ]);

            if (!$this->validateCanDelete()) {
                return false;
            }

            $result = $this->accountReceivable->delete();

            Log::info('Conta a receber excluída com sucesso', [
                'metodo'                => __METHOD__ . '@' . __LINE__,
                'account_receivable_id' => $this->accountReceivable->id,
            ]);

            $this->setSuccess();
            return $result;
        } catch (QueryException $e) {
            $this->setError('Erro ao excluir conta a receber. Ela pode estar vinculada a outros registros.');

            Log::error($this->getMessage(), [
                'metodo'                => __METHOD__ . '@' . __LINE__,
                'message'               => $this->getMessage(),
                'error_code'            => $this->getErrorCode(),
                'account_receivable_id' => $this->accountReceivable->id,
                'error_message'         => $e->getMessage(),
            ]);

            return false;
        } catch (\Exception $e) {
            $this->setError('Erro inesperado ao excluir conta a receber');

            Log::error($this->getMessage(), [
                'metodo'                => __METHOD__ . '@' . __LINE__,
                'message'               => $this->getMessage(),
                'error_code'            => $this->getErrorCode(),
                'account_receivable_id' => $this->accountReceivable->id,
                'error_message'         => $e->getMessage(),
                'trace'                 => $e->getTraceAsString(),
            ]);

            return false;
        }
    }

    private function validateCanDelete(): bool
    {
        if ($this->accountReceivable->paid) {
            $this->setError('Não é possível excluir uma conta que já foi recebida');

            Log::warning($this->getMessage(), [
                'metodo'                => __METHOD__ . '@' . __LINE__,
                'message'               => $this->getMessage(),
                'error_code'            => $this->getErrorCode(),
                'account_receivable_id' => $this->accountReceivable->id,
            ]);

            return false;
        }

        return true;
    }
}
