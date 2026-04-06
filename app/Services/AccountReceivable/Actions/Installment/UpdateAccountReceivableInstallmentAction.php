<?php

namespace App\Services\AccountReceivable\Actions\Installment;

use App\Models\AccountReceivableInstallment;
use App\Services\AccountReceivable\Validators\AccountReceivableInstallmentValidator;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class UpdateAccountReceivableInstallmentAction
{
    use HandlesActionResponse;

    public function __construct(private readonly AccountReceivableInstallment $installment) {}

    public function execute(array $data): ?AccountReceivableInstallment
    {
        try {
            $validated = AccountReceivableInstallmentValidator::validateUpdate($data);

            $this->installment->update($validated);

            $this->setSuccess();

            return $this->installment->fresh();
        } catch (ValidationException $e) {
            $this->setError('Falha de validacao dos dados da parcela', $e->errors());
            return null;
        } catch (QueryException $e) {
            $this->setError('Erro ao atualizar parcela de conta a receber no banco de dados');

            Log::error($this->getMessage(), [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'error_code' => $this->getErrorCode(),
                'error_message' => $e->getMessage(),
                'installment_id' => $this->installment->id,
                'payload' => $data,
            ]);

            return null;
        } catch (\Exception $e) {
            $this->setError('Erro inesperado ao atualizar parcela de conta a receber');

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
