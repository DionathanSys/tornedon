<?php

namespace App\Services\AccountReceivable;

use App\Enum\AccountReceivable\Status;
use App\Models\AccountReceivable;
use App\Services\AccountReceivable\Actions\CreateAccountReceivableAction;
use App\Services\AccountReceivable\Actions\DeleteAccountReceivableAction;
use App\Services\AccountReceivable\Actions\UpdateAccountReceivableAction;
use App\Traits\HandlesServiceResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AccountReceivableService
{
    use HandlesServiceResponse;

    public function create(array $data, int $createdBy): ?AccountReceivable
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($data, $createdBy) {
                $data['status'] = $data['status'] ?? Status::PENDING->value;

                $action = new CreateAccountReceivableAction($createdBy);
                $accountReceivable = $action->execute($data);

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

                $this->setSuccess('Conta a receber criada com sucesso');

                Log::info('Conta a receber criada com sucesso via service', [
                    'metodo'                => __METHOD__ . '@' . __LINE__,
                    'account_receivable_id' => $accountReceivable->id,
                ]);

                return $accountReceivable;
            });
        } catch (\Exception $e) {
            $this->setError('Erro ao criar conta a receber');

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

    public function update(AccountReceivable $accountReceivable, array $data, int $updatedBy): ?AccountReceivable
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($accountReceivable, $data, $updatedBy) {
                $action = new UpdateAccountReceivableAction($updatedBy, $accountReceivable);
                $updated = $action->execute($data);

                if ($action->hasError()) {
                    $this->setError(
                        $action->getMessage(),
                        $action->getErrors(),
                        422,
                        $action->getErrorCode()
                    );

                    Log::error($this->getMessage(), [
                        'metodo'                => __METHOD__ . '@' . __LINE__,
                        'account_receivable_id' => $accountReceivable->id,
                        'message'               => $this->getMessage(),
                        'error_code'            => $this->getErrorCode(),
                        'errors'                => $action->getErrors(),
                        'data'                  => $data,
                        'user_id'               => $updatedBy,
                    ]);

                    return null;
                }

                $this->setSuccess('Conta a receber atualizada com sucesso');

                Log::info('Conta a receber atualizada com sucesso via service', [
                    'metodo'                => __METHOD__ . '@' . __LINE__,
                    'account_receivable_id' => $accountReceivable->id,
                ]);

                return $updated;
            });
        } catch (\Exception $e) {
            $this->setError('Erro ao atualizar conta a receber');

            Log::error($this->getMessage(), [
                'metodo'                => __METHOD__ . '@' . __LINE__,
                'account_receivable_id' => $accountReceivable->id,
                'error_code'            => $this->getErrorCode(),
                'message'               => $e->getMessage(),
                'trace'                 => $e->getTraceAsString(),
                'data'                  => $data,
                'user_id'               => $updatedBy,
            ]);

            return null;
        }
    }

    public function delete(AccountReceivable $accountReceivable): bool
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($accountReceivable) {
                $action = new DeleteAccountReceivableAction($accountReceivable);
                $result = $action->execute();

                if ($action->hasError()) {
                    $this->setError(
                        $action->getMessage(),
                        $action->getErrors(),
                        422,
                        $action->getErrorCode()
                    );

                    Log::error($this->getMessage(), [
                        'metodo'                => __METHOD__ . '@' . __LINE__,
                        'account_receivable_id' => $accountReceivable->id,
                        'message'               => $action->getMessage(),
                        'error_code'            => $action->getErrorCode(),
                    ]);

                    return false;
                }

                $this->setSuccess('Conta a receber excluída com sucesso');

                Log::info('Conta a receber excluída com sucesso via service', [
                    'metodo'                => __METHOD__ . '@' . __LINE__,
                    'account_receivable_id' => $accountReceivable->id,
                ]);

                return $result;
            });
        } catch (\Exception $e) {
            $this->setError('Erro ao excluir conta a receber');

            Log::error($this->getMessage(), [
                'metodo'                => __METHOD__ . '@' . __LINE__,
                'account_receivable_id' => $accountReceivable->id,
                'error_code'            => $this->getErrorCode(),
                'message'               => $e->getMessage(),
                'trace'                 => $e->getTraceAsString(),
            ]);

            return false;
        }
    }
}
