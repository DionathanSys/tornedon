<?php

namespace App\Services\Quote\Actions;

use App\Enum\Quote\Status;
use App\Models\Quote;
use App\Services\Payment\CustomerPaymentDefaultsResolver;
use App\Services\Quote\Validators\QuoteValidator;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class CreateQuote
{
    use HandlesActionResponse;

    public function __construct(
        private int $createdBy,
    ) {}

    /**
     * Cria um novo orçamento.
     *
     * @param  array  $data
     * @return Quote|null
     */
    public function execute(array $data): ?Quote
    {
        try {
            Log::debug('CreateQuote: Iniciando criação de orçamento', [
                'metodo'  => __METHOD__ . '@' . __LINE__,
                'user_id' => $this->createdBy,
                'data'    => $data,
            ]);

            $data = app(CustomerPaymentDefaultsResolver::class)->resolve(
                (int) ($data['company_id'] ?? 0),
                isset($data['customer_id']) ? (int) $data['customer_id'] : null,
                $data['payment_method'] ?? null,
                $data['payment_condition'] ?? null,
            ) + $data;

            $validated = QuoteValidator::validateCreate($data);

            $validated['status']     = Status::DRAFT;
            $validated['created_by'] = $this->createdBy;

            if (empty($validated['valid_until'])) {
                $validated['valid_until'] = now()->addDays(30);
            }

            $quote = Quote::create($validated);

            Log::info('CreateQuote: Orçamento criado com sucesso', [
                'metodo'       => __METHOD__ . '@' . __LINE__,
                'quote_id'     => $quote->id,
                'quote_number' => $quote->quote_number,
            ]);

            $this->setSuccess();
            return $quote;

        } catch (ValidationException $e) {
            $this->setError('Falha de validação dos dados', $e->errors());

            Log::error('CreateQuote: ' . $this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'error_code' => $this->getErrorCode(),
                'errors'     => $e->errors(),
                'data'       => $data,
                'user_id'    => $this->createdBy,
            ]);

            return null;

        } catch (QueryException $e) {
            $this->setError('Erro ao salvar orçamento no banco de dados');

            Log::error('CreateQuote: ' . $this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'error_code' => $this->getErrorCode(),
                'exception'  => $e->getMessage(),
                'sql'        => $e->getSql(),
                'data'       => $data,
                'user_id'    => $this->createdBy,
            ]);

            return null;

        } catch (\Exception $e) {
            $this->setError('Erro inesperado ao criar orçamento');

            Log::error('CreateQuote: ' . $this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'error_code' => $this->getErrorCode(),
                'exception'  => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
                'data'       => $data,
                'user_id'    => $this->createdBy,
            ]);

            return null;
        }
    }
}

