<?php

namespace App\Services\AccountPayable;

use App\Enum\AccountPayable\Status;
use App\Models\AccountPayable;
use App\Services\AccountPayable\Actions\CreateAccountPayableAction;
use App\Services\AccountPayable\Actions\DeleteAccountPayableAction;
use App\Services\AccountPayable\Actions\UpdateAccountPayableAction;
use App\Traits\HandlesServiceResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AccountPayableService
{
    use HandlesServiceResponse;

    public function create(array $data, int $createdBy): ?AccountPayable
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($data, $createdBy) {
                $data['status'] = $data['status'] ?? Status::PENDING->value;

                $action = new CreateAccountPayableAction($createdBy);
                $accountPayable = $action->execute($data);

                if ($action->hasError()) {
                    $this->setError(
                        $action->getMessage(),
                        $action->getErrors(),
                        422,
                        $action->getErrorCode()
                    );

                    Log::error($this->getMessage(), [
                        'metodo'     => __METHOD__ . '@' . __LINE__,
                        'message'    => $this->getMessage(),
                        'error_code' => $this->getErrorCode(),
                        'errors'     => $action->getErrors(),
                        'data'       => $data,
                        'user_id'    => $createdBy,
                    ]);

                    return null;
                }

                $this->setSuccess('Conta a pagar criada com sucesso');

                Log::info('Conta a pagar criada com sucesso via service', [
                    'metodo'             => __METHOD__ . '@' . __LINE__,
                    'account_payable_id' => $accountPayable->id,
                ]);

                return $accountPayable;
            });
        } catch (\Exception $e) {
            $this->setError('Erro ao criar conta a pagar');

            Log::error($this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'error_code' => $this->getErrorCode(),
                'message'    => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
                'data'       => $data,
                'user_id'    => $createdBy,
            ]);

            return null;
        }
    }

    public function update(AccountPayable $accountPayable, array $data, int $updatedBy): ?AccountPayable
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($accountPayable, $data, $updatedBy) {
                $action = new UpdateAccountPayableAction($updatedBy, $accountPayable);
                $updated = $action->execute($data);

                if ($action->hasError()) {
                    $this->setError(
                        $action->getMessage(),
                        $action->getErrors(),
                        422,
                        $action->getErrorCode()
                    );

                    Log::error($this->getMessage(), [
                        'metodo'             => __METHOD__ . '@' . __LINE__,
                        'account_payable_id' => $accountPayable->id,
                        'message'            => $this->getMessage(),
                        'error_code'         => $this->getErrorCode(),
                        'errors'             => $action->getErrors(),
                        'data'               => $data,
                        'user_id'            => $updatedBy,
                    ]);

                    return null;
                }

                $this->setSuccess('Conta a pagar atualizada com sucesso');

                Log::info('Conta a pagar atualizada com sucesso via service', [
                    'metodo'             => __METHOD__ . '@' . __LINE__,
                    'account_payable_id' => $accountPayable->id,
                ]);

                return $updated;
            });
        } catch (\Exception $e) {
            $this->setError('Erro ao atualizar conta a pagar');

            Log::error($this->getMessage(), [
                'metodo'             => __METHOD__ . '@' . __LINE__,
                'account_payable_id' => $accountPayable->id,
                'error_code'         => $this->getErrorCode(),
                'message'            => $e->getMessage(),
                'trace'              => $e->getTraceAsString(),
                'data'               => $data,
                'user_id'            => $updatedBy,
            ]);

            return null;
        }
    }

    public function delete(AccountPayable $accountPayable): bool
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($accountPayable) {
                $action = new DeleteAccountPayableAction($accountPayable);
                $result = $action->execute();

                if ($action->hasError()) {
                    $this->setError(
                        $action->getMessage(),
                        $action->getErrors(),
                        422,
                        $action->getErrorCode()
                    );

                    Log::error($this->getMessage(), [
                        'metodo'             => __METHOD__ . '@' . __LINE__,
                        'account_payable_id' => $accountPayable->id,
                        'message'            => $action->getMessage(),
                        'error_code'         => $action->getErrorCode(),
                    ]);

                    return false;
                }

                $this->setSuccess('Conta a pagar excluída com sucesso');

                Log::info('Conta a pagar excluída com sucesso via service', [
                    'metodo'             => __METHOD__ . '@' . __LINE__,
                    'account_payable_id' => $accountPayable->id,
                ]);

                return $result;
            });
        } catch (\Exception $e) {
            $this->setError('Erro ao excluir conta a pagar');

            Log::error($this->getMessage(), [
                'metodo'             => __METHOD__ . '@' . __LINE__,
                'account_payable_id' => $accountPayable->id,
                'error_code'         => $this->getErrorCode(),
                'message'            => $e->getMessage(),
                'trace'              => $e->getTraceAsString(),
            ]);

            return false;
        }
    }
}
