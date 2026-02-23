<?php

namespace App\Services\Quote\Actions;

use App\Enum\Quote\Status;
use App\Models\Quote;
use App\Services\Quote\Validators\QuoteValidator;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class UpdateQuoteAction
{
    use HandlesActionResponse;

    public function __construct(
        private int   $updatedBy,
        private Quote $quote,
    ) {}

    /**
     * Atualiza um orçamento existente.
     * Somente orçamentos em rascunho podem ser editados.
     *
     * @param  array  $data
     * @return Quote|null
     */
    public function execute(array $data): ?Quote
    {
        try {
            Log::debug('UpdateQuoteAction: Iniciando atualização de orçamento', [
                'metodo'   => __METHOD__ . '@' . __LINE__,
                'quote_id' => $this->quote->id,
                'user_id'  => $this->updatedBy,
                'data'     => $data,
            ]);

            if (!in_array($this->quote->status, [Status::DRAFT, Status::SENT])) {
                $this->setError('Apenas orçamentos em rascunho podem ser editados.', [
                    'status' => ['O orçamento não pode ser editado no status atual: ' . $this->quote->status->description()],
                ]);
                return null;
            }

            $validated = QuoteValidator::validateUpdate($data, $this->quote->id);

            // Campos imutáveis
            unset($validated['company_id'], $validated['status']);

            $validated['updated_by'] = $this->updatedBy;

            $this->quote->update($validated);

            Log::info('UpdateQuoteAction: Orçamento atualizado com sucesso', [
                'metodo'       => __METHOD__ . '@' . __LINE__,
                'quote_id'     => $this->quote->id,
                'quote_number' => $this->quote->quote_number,
                'user_id'      => $this->updatedBy,
            ]);

            $this->setSuccess();
            return $this->quote;

        } catch (ValidationException $e) {
            $this->setError('Falha de validação dos dados', $e->errors());

            Log::error('UpdateQuoteAction: ' . $this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'error_code' => $this->getErrorCode(),
                'errors'     => $e->errors(),
                'quote_id'   => $this->quote->id,
                'user_id'    => $this->updatedBy,
            ]);

            return null;

        } catch (QueryException $e) {
            $this->setError('Erro ao atualizar orçamento no banco de dados');

            Log::error('UpdateQuoteAction: ' . $this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'error_code' => $this->getErrorCode(),
                'exception'  => $e->getMessage(),
                'quote_id'   => $this->quote->id,
            ]);

            return null;

        } catch (\Exception $e) {
            $this->setError('Erro inesperado ao atualizar orçamento');

            Log::error('UpdateQuoteAction: ' . $this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'error_code' => $this->getErrorCode(),
                'exception'  => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
                'quote_id'   => $this->quote->id,
            ]);

            return null;
        }
    }
}
