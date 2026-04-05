<?php

namespace App\Services\AccountPayable\Actions\Installment;

use App\Models\AccountPayableInstallment;
use App\Services\AccountPayable\Validators\AccountPayableInstallmentValidator;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class UpdateAccountPayableInstallmentAction
{
    use HandlesActionResponse;

    public function __construct(private readonly AccountPayableInstallment $installment) {}

    public function execute(array $data): ?AccountPayableInstallment
    {
        try {
            Log::debug('Iniciando atualização de parcela de conta a pagar', [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'installment_id' => $this->installment->id,
                'account_payable_id' => $this->installment->account_payable_id,
                'company_id' => $this->installment->company_id,
                'payload' => $data,
            ]);

            $validated = AccountPayableInstallmentValidator::validateUpdate($data);
            $this->installment->fill($validated);
            $this->installment->save();

            $this->setSuccess();

            Log::info('Parcela de conta a pagar atualizada com sucesso', [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'installment_id' => $this->installment->id,
                'account_payable_id' => $this->installment->account_payable_id,
            ]);

            return $this->installment->fresh();
        } catch (ValidationException $e) {
            $this->setError('Falha de validação dos dados da parcela', $e->errors());

            Log::error($this->getMessage(), [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'error_code' => $this->getErrorCode(),
                'errors' => $e->errors(),
                'installment_id' => $this->installment->id,
                'payload' => $data,
            ]);

            return null;
        } catch (QueryException $e) {
            $this->setError('Erro ao atualizar parcela no banco de dados');

            Log::error($this->getMessage(), [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'error_code' => $this->getErrorCode(),
                'error_message' => $e->getMessage(),
                'installment_id' => $this->installment->id,
                'payload' => $data,
            ]);

            return null;
        } catch (\Exception $e) {
            $this->setError('Erro inesperado ao atualizar parcela');

            Log::error($this->getMessage(), [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'error_code' => $this->getErrorCode(),
                'error_message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'installment_id' => $this->installment->id,
                'payload' => $data,
            ]);

            return null;
        }
    }
}
