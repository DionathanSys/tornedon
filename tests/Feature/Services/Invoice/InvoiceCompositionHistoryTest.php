<?php

namespace Tests\Feature\Services\Invoice;

use App\Enum\Invoice\CancellationReason;
use App\Enum\Invoice\InvoiceAction;
use App\Enum\Invoice\OperationStatus;
use App\Enum\Invoice\OperationType;
use App\Enum\Invoice\Status;
use App\Enum\Payment\Condition;
use App\Enum\Payment\Method;
use App\Enum\Requisition\Status as RequisitionStatus;
use App\Enum\ServiceOrder\State;
use App\Filament\Clusters\Financial\Resources\Invoices\Pages\EditInvoice;
use App\Filament\Clusters\Financial\Resources\Invoices\RelationManagers\InvoiceSourcesRelationManager;
use App\Filament\Clusters\Financial\Resources\Invoices\RelationManagers\ServiceOrdersRelationManager;
use App\Models\FinancialCategory;
use App\Models\Invoice;
use App\Models\InvoiceOperation;
use App\Models\Requisition;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderItem;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Invoice\Actions\ConfirmInvoiceAction;
use App\Services\Invoice\InvoiceActionEligibilityService;
use App\Services\Invoice\InvoiceCompositionService;
use App\Services\Invoice\InvoiceDraftCompositionService;
use App\Services\Invoice\InvoiceService;
use App\Services\Invoice\Support\InvoicePdfDataFormatter;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use LogicException;
use Tests\TestCase;

class InvoiceCompositionHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirmation_preserves_values_items_and_pdf_after_release_and_rebilling(): void
    {
        [$invoice, $order] = $this->draft();
        $this->confirm($invoice);
        $invoice->refresh();
        $this->assertSame(1000.0, $invoice->net_value);
        $this->assertSame(100000, $invoice->latestConfirmation->amounts['total_amount']);

        // Future reversal workflow will execute this transition after its external checks.
        $invoice->update(['status' => Status::CANCELLED, 'cancellation_reason' => CancellationReason::REBILLING]);
        $invoice->sources()->firstOrFail()->update(['released_at' => now(), 'release_reason' => 'rebilling', 'released_by' => $order->created_by]);
        $order->update(['invoice_id' => null, 'status' => State::CLOSED]);
        $order->items()->firstOrFail()->update(['unit_price' => 1200]);
        $order->items()->firstOrFail()->service->update(['name' => 'Nome alterado depois']);

        $new = $invoice->replicate(['cancellation_reason', 'confirmed_at', 'confirmed_by']);
        $new->invoice_number = 'NEW-001';
        $new->status = Status::PENDING;
        $new->save();
        $order->update(['invoice_id' => $new->id, 'status' => State::INVOICED]);
        $this->confirm($new);

        $this->assertSame(1000.0, $invoice->fresh()->total_amount);
        $this->assertSame(1200.0, $new->fresh()->total_amount);
        $this->assertFalse($invoice->fresh()->confirmed);
        $this->assertTrue($invoice->fresh()->canceled);
        $this->assertCount(1, $invoice->fresh()->latestConfirmation->sources);
        $this->assertSame($order->id, $invoice->sources()->firstOrFail()->source_id);
        $data = app(InvoicePdfDataFormatter::class)->format($invoice->fresh());
        $this->assertSame(1, $data['service_order_count']);
        $this->assertSame('R$ 1.000,00', $data['service_orders'][0]['total']);
        $this->assertStringContainsString('Serviço original', $data['service_orders'][0]['items'][0]);
        $this->assertStringNotContainsString('Nome alterado', $data['service_orders'][0]['items'][0]);
        $records = Invoice::whereIn('id', [$invoice->id, $new->id])->get();
        Invoice::preloadAmounts($records);
        $this->assertSame(1000.0, $records->firstWhere('id', $invoice->id)->total_amount);
        $this->assertSame(1200.0, $records->firstWhere('id', $new->id)->total_amount);
    }

    public function test_removing_order_from_draft_releases_related_requisition_and_preserves_removal_history(): void
    {
        [$invoice, $order] = $this->draft();
        $requisition = Requisition::create([
            'company_id' => $invoice->company_id, 'customer_id' => $invoice->customer_id,
            'number' => 'REQ-001', 'sale_date' => today(), 'status' => RequisitionStatus::INVOICED,
            'invoice_id' => $invoice->id, 'service_order_id' => $order->id,
            'status_before_invoicing' => RequisitionStatus::OPEN, 'invoiced_at' => now(), 'created_by' => $order->created_by,
        ]);
        $this->assertSame(1000.0, $invoice->net_value);
        $movements = StockMovement::count();
        app(InvoiceDraftCompositionService::class)->remove($invoice, $order, $order->created_by);

        $this->assertSame(State::CLOSED, $order->fresh()->status);
        $this->assertNull($order->fresh()->invoice_id);
        $this->assertSame(RequisitionStatus::CLOSED, $requisition->fresh()->status);
        $this->assertNull($requisition->fresh()->invoice_id);
        $this->assertNull($requisition->fresh()->invoiced_at);
        $this->assertSame(0.0, $invoice->net_value);
        $this->assertSame($movements, StockMovement::count());
        $this->assertSame(2, $invoice->sources()->where('release_reason', 'draft_removal')->count());
        $this->assertFalse(app(InvoiceActionEligibilityService::class)->evaluate($invoice, InvoiceAction::DELETE)['allowed']);
    }

    public function test_confirmed_order_cannot_be_removed_or_its_commercial_items_changed(): void
    {
        [$invoice, $order] = $this->draft();
        $this->confirm($invoice);
        try {
            app(InvoiceDraftCompositionService::class)->remove($invoice, $order, $order->created_by);
            $this->fail('Uma ordem confirmada não pode ser removida.');
        } catch (ValidationException) {
            $this->assertSame($invoice->id, $order->fresh()->invoice_id);
        }
        $this->expectException(ValidationException::class);
        $order->items()->firstOrFail()->update(['unit_price' => 1]);
    }

    public function test_new_order_cannot_be_attached_to_a_confirmed_invoice(): void
    {
        [$invoice, $order] = $this->draft();
        $this->confirm($invoice);
        $this->expectException(ValidationException::class);
        ServiceOrder::factory()->create(['company_id' => $invoice->company_id, 'customer_id' => $invoice->customer_id, 'invoice_id' => $invoice->id]);
    }

    public function test_confirmation_history_is_immutable(): void
    {
        [$invoice] = $this->draft();
        $this->confirm($invoice);
        $this->expectException(LogicException::class);
        $invoice->fresh()->latestConfirmation->update(['amounts' => ['total_amount' => 0]]);
    }

    public function test_allowed_return_to_draft_preserves_old_revision_and_reconfirmation_captures_new_values(): void
    {
        [$invoice, $order] = $this->draft();
        $invoice->update(['financial_policy' => null]);
        $category = FinancialCategory::create(['company_id' => $invoice->company_id, 'name' => 'Receita', 'is_active' => true, 'allow_receivable' => true]);
        $data = ['payment_method' => Method::PIX->value, 'payment_condition' => Condition::CASH->value, 'financial_category_id' => $category->id];
        $action = new ConfirmInvoiceAction($invoice, $invoice->created_by);
        $this->assertNotNull($action->execute($data, false), (string) $action->getMessage());
        $service = app(InvoiceService::class);
        $this->assertTrue($service->returnToPending($invoice->fresh(), $invoice->created_by), (string) $service->getMessage());
        $order->items()->firstOrFail()->update(['unit_price' => 800]);
        $this->assertSame(800.0, $invoice->fresh()->net_value);
        $action = new ConfirmInvoiceAction($invoice->fresh(), $invoice->created_by);
        $this->assertNotNull($action->execute($data, false), (string) $action->getMessage());
        $this->assertSame(2, $invoice->confirmations()->count());
        $this->assertSame(100000, $invoice->confirmations()->where('revision', 1)->firstOrFail()->amounts['total_amount']);
        $this->assertSame(80000, $invoice->fresh()->latestConfirmation->amounts['total_amount']);
        $this->assertSame(1, $invoice->accountReceivables()->count());
    }

    public function test_confirmation_failure_after_capture_rolls_back_history_and_commercial_changes(): void
    {
        [$invoice] = $this->draft();
        $this->mock(InvoiceCompositionService::class)->shouldReceive('capture')->once()->andReturnUsing(function (Invoice $invoice, int $userId): never {
            (new InvoiceCompositionService)->capture($invoice, $userId);
            throw new \RuntimeException('Falha simulada após registrar composição.');
        });
        $action = new ConfirmInvoiceAction($invoice, $invoice->created_by);
        $this->assertNull($action->execute(['payment_method' => Method::PIX->value, 'payment_condition' => Condition::CASH->value], false));
        $this->assertSame(Status::PENDING, $invoice->fresh()->status);
        $this->assertNull($invoice->fresh()->payment_method);
        $this->assertSame(0, $invoice->confirmations()->count());
        $this->assertSame(0, $invoice->sources()->count());
    }

    public function test_independent_financial_generation_can_initialize_previously_undefined_terms_without_rewriting_history(): void
    {
        [$invoice] = $this->draft();
        $invoice->update(['financial_policy' => null]);
        app(InvoiceCompositionService::class)->capture($invoice, $invoice->created_by);
        $invoice->update(['status' => Status::CONFIRMED]);
        $category = FinancialCategory::create(['company_id' => $invoice->company_id, 'name' => 'Receita', 'is_active' => true, 'allow_receivable' => true]);
        $service = app(InvoiceService::class);
        $result = $service->generateAccountReceivables($invoice->fresh(), [
            'payment_method' => Method::PIX->value, 'payment_condition' => Condition::CASH->value, 'financial_category_id' => $category->id,
        ], $invoice->created_by);
        $this->assertNotNull($result, (string) $service->getMessage());
        $this->assertSame(1000.0, $invoice->fresh()->net_value);
        $this->assertNull($invoice->fresh()->latestConfirmation->commercial_data['payment_method']);
        $this->assertSame(Method::PIX, $invoice->fresh()->payment_method);
        $this->assertSame(1, $invoice->confirmations()->count());
    }

    public function test_admin_can_remove_draft_order_and_consult_its_history(): void
    {
        [$invoice, $order] = $this->draft();
        $this->actingAs(User::findOrFail($order->created_by));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($order->company);
        Livewire::test(ServiceOrdersRelationManager::class, ['ownerRecord' => $invoice, 'pageClass' => EditInvoice::class])
            ->assertCanSeeTableRecords([$order])
            ->callTableAction('removeFromDraft', $order)
            ->assertHasNoActionErrors();
        $this->assertSame(State::CLOSED, $order->fresh()->status);
        Livewire::test(InvoiceSourcesRelationManager::class, ['ownerRecord' => $invoice->fresh(), 'pageClass' => EditInvoice::class])
            ->assertCanSeeTableRecords($invoice->sources()->get())
            ->assertSee('Serviço original')
            ->assertSee('1.000,00');
    }

    public function test_cross_company_source_is_rejected_without_releasing_any_records(): void
    {
        [$invoice, $order] = $this->draft();
        $foreign = ServiceOrder::factory()->create();
        try {
            app(InvoiceDraftCompositionService::class)->remove($invoice, $foreign, $order->created_by);
            $this->fail('Registro de outra empresa foi aceito.');
        } catch (ModelNotFoundException) {
            $this->assertSame($invoice->id, $order->fresh()->invoice_id);
            $this->assertSame(0, $invoice->sources()->count());
        }
    }

    public function test_active_operation_blocks_confirmation_and_releases_slot_when_completed(): void
    {
        [$invoice] = $this->draft();
        $operation = InvoiceOperation::create([
            'company_id' => $invoice->company_id, 'invoice_id' => $invoice->id,
            'type' => OperationType::REVERSE_BILLING, 'status' => OperationStatus::REQUESTED,
            'request_id' => (string) Str::uuid(), 'justification' => 'Teste de exclusão mútua', 'requested_by' => $invoice->created_by,
        ]);
        $rules = app(InvoiceActionEligibilityService::class);
        $this->assertSame('active_operation', $rules->evaluate($invoice, InvoiceAction::CONFIRM)['blockers'][0]['code']);
        $operation->update(['status' => OperationStatus::COMPLETED]);
        $this->assertNull($operation->fresh()->active_slot);
        $this->assertTrue($rules->evaluate($invoice, InvoiceAction::CONFIRM)['allowed']);
    }

    public function test_database_prevents_two_active_operations_for_the_same_invoice(): void
    {
        [$invoice] = $this->draft();
        $data = ['company_id' => $invoice->company_id, 'invoice_id' => $invoice->id,
            'type' => OperationType::REVERSE_BILLING, 'status' => OperationStatus::NEEDS_INTERVENTION,
            'justification' => 'Operação pendente', 'requested_by' => $invoice->created_by];
        InvoiceOperation::create($data + ['request_id' => (string) Str::uuid()]);
        $this->expectException(QueryException::class);
        InvoiceOperation::create($data + ['request_id' => (string) Str::uuid()]);
    }

    public function test_cancelled_invoice_cannot_be_reopened_or_deleted(): void
    {
        [$invoice] = $this->draft();
        $this->confirm($invoice);
        $invoice->refresh()->update(['status' => Status::CANCELLED]);
        try {
            $invoice->delete();
            $this->fail('O histórico deve ser preservado.');
        } catch (\RuntimeException) {
            $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => 'cancelled']);
        }
        $this->expectException(\RuntimeException::class);
        $invoice->update(['status' => Status::PENDING]);
    }

    private function draft(): array
    {
        $order = ServiceOrder::factory()->create(['status' => State::CLOSED]);
        User::findOrFail($order->created_by)->companies()->syncWithoutDetaching([$order->company_id => ['role' => 'owner', 'is_active' => true]]);
        $order->customer->companies()->syncWithoutDetaching([$order->company_id => ['is_active' => true, 'type' => '["customer"]']]);
        $invoice = Invoice::create([
            'company_id' => $order->company_id, 'customer_id' => $order->customer_id,
            'invoice_number' => 'HISTORY-001', 'invoice_date' => today(), 'status' => Status::PENDING,
            'financial_policy' => 'fiscal_only', 'created_by' => $order->created_by,
        ]);
        $order->update(['invoice_id' => $invoice->id, 'status' => State::INVOICED]);
        $service = Service::factory()->create(['company_id' => $invoice->company_id, 'name' => 'Serviço original']);
        ServiceOrderItem::create(['service_order_id' => $order->id, 'service_id' => $service->id, 'quantity' => 1, 'unit_price' => 1000, 'discount_amount' => 0, 'created_by' => $order->created_by]);

        return [$invoice, $order];
    }

    private function confirm(Invoice $invoice): void
    {
        $action = new ConfirmInvoiceAction($invoice, $invoice->created_by);
        $this->assertNotNull($action->execute(['payment_method' => Method::PIX->value, 'payment_condition' => Condition::CASH->value], false), (string) $action->getMessage());
    }
}
