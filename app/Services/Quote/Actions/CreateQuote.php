<?php

namespace App\Services\Quote\Actions;

use App\Enum\Quote\Status;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Services\Quote\Validators\QuoteValidator;
use App\Traits\HandlesActionResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class CreateQuote
{
    use HandlesActionResponse;

    public function __construct(
        private int $createdBy,
    ) {}

    public function execute(array $data): ?Quote
    {
        try {
            $validatedData = QuoteValidator::validate($data);
            
            DB::beginTransaction();

            $quoteData = [
                'company_id' => $validatedData['company_id'],
                'partner_id' => $validatedData['partner_id'],
                'description' => $validatedData['description'] ?? null,
                'status' => Status::DRAFT->value,
                'valid_until' => $validatedData['valid_until'] ?? now()->addDays(30),
                'observations' => $validatedData['observations'] ?? null,
                'customer_observations' => $validatedData['customer_observations'] ?? null,
                'created_by' => $this->createdBy,
            ];

            $quote = Quote::create($quoteData);

            DB::commit();
            $this->setSuccess();
            
            return $quote;
            
        } catch (ValidationException $e) {
            DB::rollBack();
            $this->setError('Falha de validação dos dados', $e->errors());
            
            Log::error(__METHOD__ . '@' . __LINE__, [
                'error_code' => $this->getErrorCode(),
                'message'    => 'Falha de validação dos dados',
                'errors'     => $e->errors(),
                'data'       => $data,
            ]);
            
            return null;
        } catch (\Exception $e) {
            DB::rollBack();
            $this->setError('Erro ao criar orçamento: ' . $e->getMessage());
            
            Log::error(__METHOD__ . '@' . __LINE__, [
                'error_code' => $this->getErrorCode(),
                'message'    => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
            ]);
            
            return null;
        }
    }
}
