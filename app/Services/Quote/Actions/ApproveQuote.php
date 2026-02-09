<?php

namespace App\Services\Quote\Actions;

use App\Enum\Quote\Status;
use App\Models\Quote;
use App\Traits\HandlesActionResponse;
use Illuminate\Support\Facades\Log;

class ApproveQuote
{
    use HandlesActionResponse;

    public function __construct(
        private int $approvedBy,
    ) {}

    public function execute(Quote $quote): bool
    {
        try {
            if (!$quote->canBeApproved()) {
                $this->setError('Este orçamento não pode ser aprovado');
                return false;
            }

            $quote->update([
                'status' => Status::APPROVED->value,
                'approved_at' => now(),
                'approved_by' => $this->approvedBy,
                'updated_by' => $this->approvedBy,
            ]);

            $this->setSuccess();
            return true;
            
        } catch (\Exception $e) {
            $this->setError('Erro ao aprovar orçamento: ' . $e->getMessage());
            
            Log::error(__METHOD__ . '@' . __LINE__, [
                'error_code' => $this->getErrorCode(),
                'message'    => $e->getMessage(),
                'quote_id'   => $quote->id,
            ]);
            
            return false;
        }
    }
}
