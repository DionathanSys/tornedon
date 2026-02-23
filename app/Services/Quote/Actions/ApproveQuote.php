<?php

namespace App\Services\Quote\Actions;

use App\Exceptions\DomainValidationException;
use App\Models\Quote;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

class ApproveQuote
{
    use HandlesActionResponse;

    public function __construct(
        private int $approvedBy,
    ) {}

    /**
     * Aprova o orçamento via State Machine (sent → approved).
     *
     * @param  Quote  $quote
     * @return Quote|null
     */
    public function execute(Quote $quote): ?Quote
    {
        try {
            Log::debug('ApproveQuote: Iniciando aprovação de orçamento', [
                'metodo'   => __METHOD__ . '@' . __LINE__,
                'quote_id' => $quote->id,
                'status'   => $quote->status,
                'user_id'  => $this->approvedBy,
            ]);

            $quote->state()->approve($quote, $this->approvedBy);

            $quote->refresh();

            Log::info('ApproveQuote: Orçamento aprovado com sucesso', [
                'metodo'   => __METHOD__ . '@' . __LINE__,
                'quote_id' => $quote->id,
            ]);

            $this->setSuccess();
            return $quote;

        } catch (DomainValidationException $e) {
            $this->setError('Transição inválida', $e->errors);

            Log::warning('ApproveQuote: Transição inválida', [
                'metodo'   => __METHOD__ . '@' . __LINE__,
                'quote_id' => $quote->id,
                'errors'   => $e->errors,
            ]);

            return null;

        } catch (QueryException $e) {
            $this->setError('Erro ao aprovar orçamento no banco de dados');

            Log::error('ApproveQuote: ' . $this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'error_code' => $this->getErrorCode(),
                'exception'  => $e->getMessage(),
                'quote_id'   => $quote->id,
            ]);

            return null;
        }
    }
}

