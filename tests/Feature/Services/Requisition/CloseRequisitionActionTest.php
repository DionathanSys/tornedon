<?php

namespace Tests\Feature\Services\Requisition;

use App\Enum\Requisition\Status;
use App\Models\AuditEntry;
use App\Models\Company;
use App\Models\Partner;
use App\Models\Requisition;
use App\Models\User;
use App\Services\Requisition\Actions\CloseRequisitionAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CloseRequisitionActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_closes_requisition_and_records_audit_entry(): void
    {
        $user = User::factory()->create();

        $company = Company::query()->create([
            'name' => 'Empresa Requisicao',
            'document_number' => '12345678000999',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'created_by' => $user->id,
        ]);

        $customer = Partner::query()->create([
            'name' => 'Cliente Requisicao',
            'document_type' => 'CPF',
            'document_number' => '12345678911',
            'created_by' => $user->id,
        ]);

        $requisition = Requisition::query()->create([
            'number' => 'REQ-00023',
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'sale_date' => '2026-04-22',
            'status' => Status::OPEN,
            'stock_reserved' => true,
            'stock_consumed' => false,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $result = (new CloseRequisitionAction($user->id))->execute($requisition);

        $this->assertNotNull($result);
        $this->assertSame(Status::CLOSED, $result->fresh()->status);
        $this->assertDatabaseHas('audit_entries', [
            'company_id' => $company->id,
            'auditable_type' => 'requisition',
            'auditable_id' => $requisition->id,
            'actor_user_id' => $user->id,
            'event' => 'requisition.closed',
            'action' => 'closed',
        ]);
        $this->assertSame(
            1,
            AuditEntry::query()
                ->where('event', 'requisition.closed')
                ->where('auditable_id', $requisition->id)
                ->count()
        );
    }
}
