<?php

namespace App\Services\AccountPayable\Actions;

use App\Models\AccountPayable;
use App\Services\AccountPayable\Validators\AccountPayableValidator;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class UpdateAccountPayableAction
{
    use HandlesActionResponse;

    public function __construct(
        private int            $updatedBy,
        private AccountPayable $accountPayable,
    ) {}

    public function execute(array $data): ?AccountPayable
    {
        try {
            Log::debug('Iniciando atualização de conta a pagar', [
                'metodo'             => __METHOD__ . '@' . __LINE__,
                'account_payable_id' => $this->accountPayable->id,
                'user_id'            => $this->updatedBy,
                'data'               => $data,
            ]);

            $validated = AccountPayableValidator::validateUpdate($data, $this->accountPayable->id);

            unset($validated['company_id']);

            $this->accountPayable->update($validated);

            Log::info('Conta a pagar atualizada com sucesso', [
                'metodo'             => __METHOD__ . '@' . __LINE__,
                'account_payable_id' => $this->accountPayable->id,
                'user_id'            => $this->updatedBy,
            ]);

            $this->setSuccess();
            return $this->accountPayable;
        } catch (ValidationException $e) {
            $this->setError('Falha de validação dos dados', $e->errors());

            Log::error($this->getMessage(), [
                'metodo'             => __METHOD__ . '@' . __LINE__,
                'message'            => $this->getMessage(),
                'error_code'         => $this->getErrorCode(),
                'account_payable_id' => $this->accountPayable->id,
                'errors'             => $e->errors(),
                'data'               => $data,
                'user_id'            => $this->updatedBy,
            ]);

            return null;
        } catch (QueryException $e) {
            $this->setError('Erro ao atualizar conta a pagar no banco de dados');

            Log::error($this->getMessage(), [
                'metodo'             => __METHOD__ . '@' . __LINE__,
                'message'            => $this->getMessage(),
                'error_code'         => $this->getErrorCode(),
                'account_payable_id' => $this->accountPayable->id,
                'error_message'      => $e->getMessage(),
                'data'               => $data,
                'user_id'            => $this->updatedBy,
            ]);

            return null;
        } catch (\Exception $e) {
            $this->setError('Erro inesperado ao atualizar conta a pagar');

            Log::error($this->getMessage(), [
                'metodo'             => __METHOD__ . '@' . __LINE__,
                'message'            => $this->getMessage(),
                'error_code'         => $this->getErrorCode(),
                'account_payable_id' => $this->accountPayable->id,
                'error_message'      => $e->getMessage(),
                'trace'              => $e->getTraceAsString(),
                'data'               => $data,
                'user_id'            => $this->updatedBy,
            ]);

            return null;
        }
    }
}
