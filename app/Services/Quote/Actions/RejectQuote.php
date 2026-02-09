<?php

namespace App\Services\Quote\Actions;

use App\Enum\Quote\Status;
use App\Models\Quote;
use App\Traits\HandlesActionResponse;
use Illuminate\Support\Facades\Log;

class RejectQuote
{
    use HandlesActionResponse;

    public function __construct(
        private int $rejectedBy,
    ) {}

    public function execute(Quote $quote, string $reason): bool
    {
        try {
            if (!$quote->canBeRejected()) {
                $this->setError('Este orçamento não pode ser rejeitado');
                return false;
            }

            if (empty($reason)) {
                $this->setError('Motivo da rejeição é obrigatório');
                return false;
            }

            $quote->update([
                'status' => Status::REJECTED->value,
                'rejected_reason' => $reason,
                'updated_by' => $this->rejectedBy,
            ]);

            $this->setSuccess();
            return true;
            
        } catch (\Exception $e) {
            $this->setError('Erro ao rejeitar orçamento: ' . $e->getMessage());
            
            Log::error(__METHOD__ . '@' . __LINE__, [
                'error_code' => $this->getErrorCode(),
                'message'    => $e->getMessage(),
                'quote_id'   => $quote->id,
            ]);
            
            return false;
        }
    }
}
