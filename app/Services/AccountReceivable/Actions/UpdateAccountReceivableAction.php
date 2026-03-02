<?php

namespace App\Services\AccountReceivable\Actions;

use App\Models\AccountReceivable;
use App\Services\AccountReceivable\Validators\AccountReceivableValidator;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class UpdateAccountReceivableAction
{
    use HandlesActionResponse;

    public function __construct(
        private int               $updatedBy,
        private AccountReceivable $accountReceivable,
    ) {}

    public function execute(array $data): ?AccountReceivable
    {
        try {
            Log::debug('Iniciando atualização de conta a receber', [
                'metodo'                => __METHOD__ . '@' . __LINE__,
                'account_receivable_id' => $this->accountReceivable->id,
                'user_id'               => $this->updatedBy,
                'data'                  => $data,
            ]);

            $validated = AccountReceivableValidator::validateUpdate($data, $this->accountReceivable->id);

            unset($validated['company_id']);

            $this->accountReceivable->update($validated);

            Log::info('Conta a receber atualizada com sucesso', [
                'metodo'                => __METHOD__ . '@' . __LINE__,
                'account_receivable_id' => $this->accountReceivable->id,
                'user_id'               => $this->updatedBy,
            ]);

            $this->setSuccess();
            return $this->accountReceivable;
        } catch (ValidationException $e) {
            $this->setError('Falha de validação dos dados', $e->errors());

            Log::error($this->getMessage(), [
                'metodo'                => __METHOD__ . '@' . __LINE__,
                'message'               => $this->getMessage(),
                'error_code'            => $this->getErrorCode(),
                'account_receivable_id' => $this->accountReceivable->id,
                'errors'                => $e->errors(),
                'data'                  => $data,
                'user_id'               => $this->updatedBy,
            ]);

            return null;
        } catch (QueryException $e) {
            $this->setError('Erro ao atualizar conta a receber no banco de dados');

            Log::error($this->getMessage(), [
                'metodo'                => __METHOD__ . '@' . __LINE__,
                'message'               => $this->getMessage(),
                'error_code'            => $this->getErrorCode(),
                'account_receivable_id' => $this->accountReceivable->id,
                'error_message'         => $e->getMessage(),
                'data'                  => $data,
                'user_id'               => $this->updatedBy,
            ]);

            return null;
        } catch (\Exception $e) {
            $this->setError('Erro inesperado ao atualizar conta a receber');

            Log::error($this->getMessage(), [
                'metodo'                => __METHOD__ . '@' . __LINE__,
                'message'               => $this->getMessage(),
                'error_code'            => $this->getErrorCode(),
                'account_receivable_id' => $this->accountReceivable->id,
                'error_message'         => $e->getMessage(),
                'trace'                 => $e->getTraceAsString(),
                'data'                  => $data,
                'user_id'               => $this->updatedBy,
            ]);

            return null;
        }
    }
}
