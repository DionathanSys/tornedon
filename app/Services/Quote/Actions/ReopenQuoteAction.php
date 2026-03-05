<?php

namespace App\Services\Quote\Actions;

use App\Events\Quote\QuoteReopened;
use App\Exceptions\DomainValidationException;
use App\Models\Quote;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

class ReopenQuoteAction
{
    use HandlesActionResponse;

    public function __construct(
        private int $userId,
    ) {}

    /**
     * Reabre o orçamento via State Machine (rejected|expired → draft).
     *
     * @param  Quote  $quote
     * @return Quote|null
     */
    public function execute(Quote $quote): ?Quote
    {
        try {
            Log::debug('ReopenQuoteAction: Reabrindo orçamento', [
                'metodo'   => __METHOD__ . '@' . __LINE__,
                'quote_id' => $quote->id,
                'status'   => $quote->status,
                'user_id'  => $this->userId,
                'key'      => 'reopen_quote_action',
            ]);

            $quote->state()->reopen($quote, $this->userId);

            $quote->refresh();

            QuoteReopened::dispatch($quote, $this->userId);

            Log::info('ReopenQuoteAction: Orçamento reaberto com sucesso', [
                'metodo'   => __METHOD__ . '@' . __LINE__,
                'quote_id' => $quote->id,
            ]);

            $this->setSuccess();
            return $quote;

        } catch (DomainValidationException $e) {
            $this->setError('Transição inválida', $e->errors);

            Log::warning('ReopenQuoteAction: Transição inválida', [
                'metodo'   => __METHOD__ . '@' . __LINE__,
                'quote_id' => $quote->id,
                'errors'   => $e->errors,
            ]);

            return null;

        } catch (QueryException $e) {
            $this->setError('Erro ao reabrir orçamento no banco de dados');

            Log::error('ReopenQuoteAction: ' . $this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'error_code' => $this->getErrorCode(),
                'exception'  => $e->getMessage(),
                'quote_id'   => $quote->id,
            ]);

            return null;
        }
    }
}
