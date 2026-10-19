<?php

namespace Tests\Concerns;

use App\Enum\Commitment\ImportType;
use App\Enum\ServiceOrder\State;
use App\Models\CommitmentImport;
use App\Models\CommitmentSetting;
use App\Models\Company;
use App\Models\CustomerCommitment;
use App\Models\Equipment;
use App\Models\Partner;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\User;

trait CreatesCommitmentReviewFixtures
{
    protected Company $company;

    protected Partner $customer;

    protected User $user;

    protected int $fixtureSequence = 0;

    protected function createReviewContext(): void
    {
        $this->company = Company::factory()->create();
        $this->customer = Partner::factory()->create();
        $this->company->partners()->attach($this->customer, ['type' => json_encode(['customer']), 'is_active' => true]);
        $this->user = User::factory()->create();
        $this->user->companies()->attach($this->company, ['role' => 'member', 'is_active' => true]);
        CommitmentSetting::create(['company_id' => $this->company->id, 'customer_id' => $this->customer->id, 'is_enabled' => true]);
    }

    protected function commitment(array $attributes = [], array $issues = []): CustomerCommitment
    {
        $sequence = ++$this->fixtureSequence;
        $import = CommitmentImport::create(['company_id' => $this->company->id, 'customer_id' => $this->customer->id, 'type' => ImportType::COMMITMENTS]);
        $file = $import->files()->create([
            'company_id' => $this->company->id, 'customer_id' => $this->customer->id,
            'type' => ImportType::COMMITMENTS, 'original_name' => 'empenho-'.$sequence.'.pdf',
            'sha256' => hash('sha256', (string) $sequence), 'status' => $issues === [] ? 'processed' : 'needs_review',
            'extracted_data' => ['issues' => $issues],
        ]);

        return CustomerCommitment::create([
            'company_id' => $this->company->id, 'customer_id' => $this->customer->id,
            'commitment_import_file_id' => $file->id, 'number' => (string) (14727 + $sequence),
            'fiscal_year' => 2026, 'issued_at' => '2026-06-23', 'patrimony' => '142186',
            'object' => 'Serviços para veículo PAT 142186', 'amount' => 400, 'retained_amount' => 0, 'net_amount' => 400,
            'original_data' => ['original_number' => (14727 + $sequence).'/2026', 'patrimony' => '142186', 'amount_cents' => 40000],
            ...$attributes,
        ])->fresh();
    }

    protected function order(string|float|int $amount = 400, array $attributes = [], string $equipmentName = 'CAMINHÃO PAT 142186'): ServiceOrder
    {
        $companyId = $attributes['company_id'] ?? $this->company->id;
        $customerId = $attributes['customer_id'] ?? $this->customer->id;
        $equipment = Equipment::create(['name' => $equipmentName, 'owner_id' => $customerId, 'company_id' => $companyId, 'type' => 'truck']);
        $order = ServiceOrder::factory()->create([
            'company_id' => $companyId, 'customer_id' => $customerId, 'equipment_id' => $equipment->id,
            'order_date' => '2026-01-01', 'status' => State::CLOSED, 'created_by' => $this->user->id, ...$attributes,
        ]);
        $service = Service::factory()->create(['company_id' => $companyId]);
        $order->items()->create(['service_id' => $service->id, 'quantity' => 1, 'unit_price' => $amount, 'discount_amount' => 0]);

        return $order->fresh();
    }

    protected function correctedData(CustomerCommitment $record, array $overrides = []): array
    {
        return [
            'number' => $record->number, 'fiscal_year' => $record->fiscal_year, 'issued_at' => $record->issued_at->toDateString(),
            'patrimony' => $record->patrimony, 'object' => $record->object, 'amount' => $record->amount,
            'retained_amount' => $record->retained_amount, 'net_amount' => $record->net_amount,
            'reason' => 'Conferido com o PDF original', ...$overrides,
        ];
    }
}
