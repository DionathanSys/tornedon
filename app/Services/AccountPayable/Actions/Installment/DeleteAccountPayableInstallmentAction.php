<?php

namespace App\Services\AccountPayable\Actions\Installment;

use App\Models\AccountPayableInstallment;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class DeleteAccountPayableInstallmentAction
{
    use HandlesActionResponse;

    public function __construct(private readonly AccountPayableInstallment $installment) {}

    public function execute(): bool
    {
        try {
            Log::debug('Iniciando exclusão de parcela de conta a pagar', [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'installment_id' => $this->installment->id,
                'account_payable_id' => $this->installment->account_payable_id,
                'company_id' => $this->installment->company_id,
            ]);

            if ($this->installment->payments()->exists()) {
                throw ValidationException::withMessages([
                    'installment' => ['Não é possível excluir parcela com pagamento registrado.'],
                ]);
            }

            $this->installment->delete();
            $this->setSuccess();

            Log::info('Parcela de conta a pagar excluída com sucesso', [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'installment_id' => $this->installment->id,
                'account_payable_id' => $this->installment->account_payable_id,
            ]);

            return true;
        } catch (ValidationException $e) {
            $this->setError('Falha de validação para exclusão da parcela', $e->errors());

            Log::error($this->getMessage(), [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'error_code' => $this->getErrorCode(),
                'errors' => $e->errors(),
                'installment_id' => $this->installment->id,
            ]);

            return false;
        } catch (QueryException $e) {
            $this->setError('Erro ao excluir parcela no banco de dados');

            Log::error($this->getMessage(), [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'error_code' => $this->getErrorCode(),
                'error_message' => $e->getMessage(),
                'installment_id' => $this->installment->id,
            ]);

            return false;
        } catch (\Exception $e) {
            $this->setError('Erro inesperado ao excluir parcela');

            Log::error($this->getMessage(), [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'error_code' => $this->getErrorCode(),
                'error_message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'installment_id' => $this->installment->id,
            ]);

            return false;
        }
    }
}
