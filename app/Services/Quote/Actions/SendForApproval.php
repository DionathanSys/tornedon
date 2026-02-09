<?php

namespace App\Services\Quote\Actions;

use App\Enum\Quote\Status;
use App\Models\Quote;
use App\Traits\HandlesActionResponse;
use Illuminate\Support\Facades\Log;

class SendForApproval
{
    use HandlesActionResponse;

    public function __construct(
        private int $userId,
    ) {}

    public function execute(Quote $quote): bool
    {
        try {
            if ($quote->status !== Status::DRAFT) {
                $this->setError('Apenas orçamentos em rascunho podem ser enviados');
                return false;
            }

            if ($quote->items->isEmpty()) {
                $this->setError('Orçamento deve ter ao menos um item');
                return false;
            }

            $quote->update([
                'status' => Status::SENT->value,
                'updated_by' => $this->userId,
            ]);

            $this->setSuccess();
            return true;
            
        } catch (\Exception $e) {
            $this->setError('Erro ao enviar orçamento: ' . $e->getMessage());
            
            Log::error(__METHOD__ . '@' . __LINE__, [
                'error_code' => $this->getErrorCode(),
                'message'    => $e->getMessage(),
                'quote_id'   => $quote->id,
            ]);
            
            return false;
        }
    }
}
