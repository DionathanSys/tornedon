<?php

namespace App\Services\AccountPayable\Actions\Installment;

use App\Models\AccountPayableInstallment;
use App\Services\AccountPayable\Validators\AccountPayableInstallmentValidator;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class CreateAccountPayableInstallmentAction
{
    use HandlesActionResponse;

    public function execute(array $data): ?AccountPayableInstallment
    {
        try {
            Log::debug('Iniciando criação de parcela de conta a pagar', [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'account_payable_id' => $data['account_payable_id'] ?? null,
                'company_id' => $data['company_id'] ?? null,
                'payload' => $data,
            ]);

            $validated = AccountPayableInstallmentValidator::validateCreate($data);
            $installment = AccountPayableInstallment::create($validated);

            $this->setSuccess();

            Log::info('Parcela de conta a pagar criada com sucesso', [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'installment_id' => $installment->id,
                'account_payable_id' => $installment->account_payable_id,
            ]);

            return $installment;
        } catch (ValidationException $e) {
            $this->setError('Falha de validação dos dados da parcela', $e->errors());

            Log::error($this->getMessage(), [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'error_code' => $this->getErrorCode(),
                'errors' => $e->errors(),
                'account_payable_id' => $data['account_payable_id'] ?? null,
                'company_id' => $data['company_id'] ?? null,
                'payload' => $data,
            ]);

            return null;
        } catch (QueryException $e) {
            $this->setError('Erro ao salvar parcela no banco de dados');

            Log::error($this->getMessage(), [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'error_code' => $this->getErrorCode(),
                'error_message' => $e->getMessage(),
                'account_payable_id' => $data['account_payable_id'] ?? null,
                'company_id' => $data['company_id'] ?? null,
                'payload' => $data,
            ]);

            return null;
        } catch (\Exception $e) {
            $this->setError('Erro inesperado ao criar parcela');

            Log::error($this->getMessage(), [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'error_code' => $this->getErrorCode(),
                'error_message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'account_payable_id' => $data['account_payable_id'] ?? null,
                'company_id' => $data['company_id'] ?? null,
                'payload' => $data,
            ]);

            return null;
        }
    }
}
