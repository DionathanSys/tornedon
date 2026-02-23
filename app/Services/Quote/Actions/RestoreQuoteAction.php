<?php

namespace App\Services\Quote\Actions;

use App\Models\Quote;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

class RestoreQuoteAction
{
    use HandlesActionResponse;

    public function __construct(
        private Quote $quote,
    ) {}

    /**
     * Restaura um orçamento excluído (soft delete).
     *
     * @return bool
     */
    public function execute(): bool
    {
        try {
            Log::debug('RestoreQuoteAction: Restaurando orçamento', [
                'metodo'   => __METHOD__ . '@' . __LINE__,
                'quote_id' => $this->quote->id,
            ]);

            $result = $this->quote->restore();

            Log::info('RestoreQuoteAction: Orçamento restaurado com sucesso', [
                'metodo'       => __METHOD__ . '@' . __LINE__,
                'quote_id'     => $this->quote->id,
                'quote_number' => $this->quote->quote_number,
            ]);

            $this->setSuccess();
            return (bool) $result;

        } catch (QueryException $e) {
            $this->setError('Erro ao restaurar orçamento no banco de dados');

            Log::error('RestoreQuoteAction: ' . $this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'error_code' => $this->getErrorCode(),
                'exception'  => $e->getMessage(),
                'quote_id'   => $this->quote->id,
            ]);

            return false;

        } catch (\Exception $e) {
            $this->setError('Erro inesperado ao restaurar orçamento');

            Log::error('RestoreQuoteAction: ' . $this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'error_code' => $this->getErrorCode(),
                'exception'  => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
                'quote_id'   => $this->quote->id,
            ]);

            return false;
        }
    }
}
