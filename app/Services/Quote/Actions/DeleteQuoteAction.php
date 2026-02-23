<?php

namespace App\Services\Quote\Actions;

use App\Enum\Quote\Status;
use App\Models\Quote;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

class DeleteQuoteAction
{
    use HandlesActionResponse;

    public function __construct(
        private Quote $quote,
    ) {}

    /**
     * Exclui (soft delete) um orçamento.
     *
     * @return bool
     */
    public function execute(): bool
    {
        try {
            Log::debug('DeleteQuoteAction: Iniciando exclusão (soft delete) de orçamento', [
                'metodo'       => __METHOD__ . '@' . __LINE__,
                'quote_id'     => $this->quote->id,
                'quote_number' => $this->quote->quote_number,
            ]);

            if (! $this->validateCanDelete()) {
                return false;
            }

            $result = $this->quote->delete();

            Log::info('DeleteQuoteAction: Orçamento excluído (soft delete) com sucesso', [
                'metodo'       => __METHOD__ . '@' . __LINE__,
                'quote_id'     => $this->quote->id,
                'quote_number' => $this->quote->quote_number,
            ]);

            $this->setSuccess();
            return (bool) $result;

        } catch (QueryException $e) {
            $this->setError('Erro ao excluir orçamento. Ele pode estar vinculado a outros registros.');

            Log::error('DeleteQuoteAction: ' . $this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'error_code' => $this->getErrorCode(),
                'exception'  => $e->getMessage(),
                'quote_id'   => $this->quote->id,
            ]);

            return false;

        } catch (\Exception $e) {
            $this->setError('Erro inesperado ao excluir orçamento');

            Log::error('DeleteQuoteAction: ' . $this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'error_code' => $this->getErrorCode(),
                'exception'  => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
                'quote_id'   => $this->quote->id,
            ]);

            return false;
        }
    }

    /**
     * Exclui permanentemente um orçamento (force delete).
     *
     * @return bool
     */
    public function forceDelete(): bool
    {
        try {
            Log::debug('DeleteQuoteAction: Iniciando exclusão permanente de orçamento', [
                'metodo'       => __METHOD__ . '@' . __LINE__,
                'quote_id'     => $this->quote->id,
                'quote_number' => $this->quote->quote_number,
            ]);

            $result = $this->quote->forceDelete();

            Log::info('DeleteQuoteAction: Orçamento excluído permanentemente com sucesso', [
                'metodo'   => __METHOD__ . '@' . __LINE__,
                'quote_id' => $this->quote->id,
            ]);

            $this->setSuccess();
            return (bool) $result;

        } catch (QueryException $e) {
            $this->setError('Erro ao excluir permanentemente o orçamento. Ele pode estar vinculado a outros registros.');

            Log::error('DeleteQuoteAction (force): ' . $this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'error_code' => $this->getErrorCode(),
                'exception'  => $e->getMessage(),
                'quote_id'   => $this->quote->id,
            ]);

            return false;

        } catch (\Exception $e) {
            $this->setError('Erro inesperado ao excluir permanentemente o orçamento');

            Log::error('DeleteQuoteAction (force): ' . $this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'error_code' => $this->getErrorCode(),
                'exception'  => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
                'quote_id'   => $this->quote->id,
            ]);

            return false;
        }
    }

    /**
     * Verifica se o orçamento pode ser excluído.
     * Orçamentos aprovados com ordem de produção associada não podem ser excluídos.
     */
    private function validateCanDelete(): bool
    {
        if (
            $this->quote->status === Status::APPROVED
            && $this->quote->productionOrder()->exists()
        ) {
            $this->setError('Não é possível excluir um orçamento aprovado que já possui uma ordem de produção vinculada.');
            return false;
        }

        return true;
    }
}
