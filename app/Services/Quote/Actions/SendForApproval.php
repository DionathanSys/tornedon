<?php

namespace App\Services\Quote\Actions;

use App\Exceptions\DomainValidationException;
use App\Models\Quote;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

class SendForApproval
{
    use HandlesActionResponse;

    public function __construct(
        private int $userId,
    ) {}

    /**
     * Envia o orçamento para aprovação via State Machine (draft → sent).
     *
     * @param  Quote  $quote
     * @return Quote|null
     */
    public function execute(Quote $quote): ?Quote
    {
        try {
            Log::debug('SendForApproval: Iniciando envio para aprovação', [
                'metodo'   => __METHOD__ . '@' . __LINE__,
                'quote_id' => $quote->id,
                'status'   => $quote->status,
                'user_id'  => $this->userId,
            ]);

            $quote->state()->sendForApproval($quote, $this->userId);

            $quote->refresh();

            Log::info('SendForApproval: Orçamento enviado para aprovação com sucesso', [
                'metodo'   => __METHOD__ . '@' . __LINE__,
                'quote_id' => $quote->id,
            ]);

            $this->setSuccess();
            return $quote;

        } catch (DomainValidationException $e) {
            $this->setError('Transição inválida', $e->errors);

            Log::warning('SendForApproval: Transição inválida', [
                'metodo'   => __METHOD__ . '@' . __LINE__,
                'quote_id' => $quote->id,
                'errors'   => $e->errors,
            ]);

            return null;

        } catch (QueryException $e) {
            $this->setError('Erro ao enviar orçamento para aprovação no banco de dados');

            Log::error('SendForApproval: ' . $this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'error_code' => $this->getErrorCode(),
                'exception'  => $e->getMessage(),
                'quote_id'   => $quote->id,
            ]);

            return null;
        }
    }
}

