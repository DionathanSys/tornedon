<?php

namespace App\Services\AccountPayable\Actions;

use App\Models\AccountPayable;
use App\Services\AccountPayable\Validators\AccountPayableValidator;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class CreateAccountPayableAction
{
    use HandlesActionResponse;

    public function __construct(
        private int $createdBy,
    ) {}

    public function execute(array $data): ?AccountPayable
    {
        try {
            Log::debug('Iniciando criação de conta a pagar', [
                'metodo'  => __METHOD__ . '@' . __LINE__,
                'user_id' => $this->createdBy,
                'data'    => $data,
            ]);

            $validated = AccountPayableValidator::validateCreate($data);

            $accountPayable = AccountPayable::create($validated);

            Log::info('Conta a pagar criada com sucesso', [
                'metodo'             => __METHOD__ . '@' . __LINE__,
                'account_payable_id' => $accountPayable->id,
            ]);

            $this->setSuccess();
            return $accountPayable;
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
            $this->setError('Erro ao salvar conta a pagar no banco de dados');

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
            $this->setError('Erro inesperado ao criar conta a pagar');

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
