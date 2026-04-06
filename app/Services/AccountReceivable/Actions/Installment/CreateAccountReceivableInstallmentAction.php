<?php

namespace App\Services\AccountReceivable\Actions\Installment;

use App\Models\AccountReceivableInstallment;
use App\Services\AccountReceivable\Validators\AccountReceivableInstallmentValidator;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class CreateAccountReceivableInstallmentAction
{
    use HandlesActionResponse;

    public function execute(array $data): ?AccountReceivableInstallment
    {
        try {
            $validated = AccountReceivableInstallmentValidator::validateCreate($data);

            $installment = AccountReceivableInstallment::create($validated);

            $this->setSuccess();

            return $installment;
        } catch (ValidationException $e) {
            $this->setError('Falha de validacao dos dados da parcela', $e->errors());
            return null;
        } catch (QueryException $e) {
            $this->setError('Erro ao salvar parcela de conta a receber no banco de dados');

            Log::error($this->getMessage(), [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'error_code' => $this->getErrorCode(),
                'error_message' => $e->getMessage(),
                'payload' => $data,
            ]);

            return null;
        } catch (\Exception $e) {
            $this->setError('Erro inesperado ao criar parcela de conta a receber');

            Log::error($this->getMessage(), [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'error_code' => $this->getErrorCode(),
                'error_message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'payload' => $data,
            ]);

            return null;
        }
    }
}
