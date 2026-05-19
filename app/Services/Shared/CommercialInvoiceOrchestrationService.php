<?php

namespace App\Services\Shared;

use App\Models\Invoice;
use App\Traits\HandlesServiceResponse;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class CommercialInvoiceOrchestrationService
{
    use HandlesServiceResponse;

    public function __construct(
        private readonly CommercialInvoiceCreationService $invoiceCreator,
    ) {}

    public function createAndAttach(
        Collection $records,
        int $userId,
        array $invoiceData,
        callable $attachRecords,
        string $successMessage,
    ): ?Invoice {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($records, $userId, $invoiceData, $attachRecords, $successMessage): Invoice {
                $first = $records->first();

                $invoice = $this->invoiceCreator->createFromRecord($first, $userId, $invoiceData);

                $attachRecords($records, $invoice, $userId);

                $this->setSuccess($successMessage);

                return $invoice;
            });
        } catch (\Exception $e) {
            $this->setError($e->getMessage(), method_exists($this->invoiceCreator, 'errors') ? $this->invoiceCreator->errors() : []);

            return null;
        }
    }

    public function attachToExisting(
        Collection $records,
        Invoice $invoice,
        int $userId,
        callable $attachRecords,
        string $successMessage,
    ): ?Invoice {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($records, $invoice, $userId, $attachRecords, $successMessage): Invoice {
                $attachRecords($records, $invoice, $userId);

                $this->setSuccess($successMessage);

                return $invoice->fresh();
            });
        } catch (\Exception $e) {
            $this->setError($e->getMessage());

            return null;
        }
    }
}
