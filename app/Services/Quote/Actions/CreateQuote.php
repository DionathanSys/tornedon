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

            // Create quote items
            foreach ($validatedData['items'] as $index => $itemData) {
                $quantity = $itemData['quantity'];
                $unitPrice = $itemData['unit_price'];
                $discountAmount = $itemData['discount_amount'] ?? 0;
                $discountPercentage = $itemData['discount_percentage'] ?? 0;

                if ($discountAmount == 0 && $discountPercentage > 0) {
                    $discountAmount = ($quantity * $unitPrice) * ($discountPercentage / 100);
                }

                QuoteItem::create([
                    'quote_id' => $quote->id,
                    'product_id' => $itemData['product_id'] ?? null,
                    'description' => $itemData['description'],
                    'quantity' => $quantity,
                    'unit_of_measure' => $itemData['unit_of_measure'],
                    'unit_price' => $unitPrice,
                    'discount_percentage' => $discountPercentage,
                    'discount_amount' => $discountAmount,
                    'technical_specifications' => $itemData['technical_specifications'] ?? null,
                    'estimated_production_hours' => $itemData['estimated_production_hours'] ?? null,
                    'material_cost' => $itemData['material_cost'] ?? null,
                    'labor_cost' => $itemData['labor_cost'] ?? null,
                    'sequence' => $index + 1,
                ]);
            }

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
