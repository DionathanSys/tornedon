<?php

namespace Tests\Feature\Filament\Invoices;

use App\Enum\FiscalDocument\DocumentModel;
use App\Enum\FiscalDocument\NfeStatus;
use App\Enum\FiscalDocument\Status;
use App\Enum\Invoice\Status as InvoiceStatus;
use App\Enum\ServiceOrder\State;
use App\Filament\Clusters\Financial\Resources\Invoices\Pages\EditInvoice;
use App\Filament\Clusters\Financial\Resources\Invoices\RelationManagers\FiscalDocumentsRelationManager;
use App\Filament\Clusters\Sales\Resources\FiscalDocuments\Pages\EditFiscalDocument;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentPayload;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderItem;
use App\Models\User;
use App\Services\FiscalDocument\NfeDocumentService;
use App\Services\FiscalDocument\NfseDocumentService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class InvoiceFiscalActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_nfe_correction_and_download_actions_have_the_same_visibility_in_header_and_invoice(): void
    {
        [$invoice, $document] = $this->context();
        FiscalDocumentPayload::create(['company_id' => $document->company_id, 'fiscal_document_id' => $document->id, 'nfe_payload' => ['correcoes' => [['sequencial' => 1, 'pdf_base64' => base64_encode('%PDF-test')]]]]);
        $header = Livewire::test(EditFiscalDocument::class, ['record' => $document->id]);
        $table = Livewire::test(FiscalDocumentsRelationManager::class, ['ownerRecord' => $invoice, 'pageClass' => EditInvoice::class]);
        foreach (['cancelar_nfe', 'corrigir_nfe', 'baixar_carta_correcao_nfe', 'danfe', 'download_xml'] as $name) {
            $header->assertActionVisible($name);
            $table->assertTableActionVisible($name, $document);
        }
        foreach (['emitir', 'emitir_nfse', 'cancelar_nfse', 'pdf_nfse', 'reconciliar_rps_nfse'] as $name) {
            $header->assertActionHidden($name);
            $table->assertTableActionHidden($name, $document);
        }
        $service = $this->mock(NfeDocumentService::class);
        $service->shouldReceive('corrigir')->once()->withArgs(fn ($record, $text, $sequence, $userId): bool => $record->id === $document->id && $text === 'Correção informada pela empresa emitente.' && $sequence === null && $userId === auth()->id())->andReturnTrue();
        $service->shouldReceive('isSuccess')->andReturnTrue();
        $service->shouldReceive('getMessage')->andReturn('Carta de correção emitida.');
        $table->callTableAction('corrigir_nfe', $document, ['justificativa' => 'Correção informada pela empresa emitente.'])
            ->assertHasNoActionErrors()->assertDispatched('invoice-fiscal-state-changed');
    }

    public function test_nfse_cancellation_releases_new_document_generation_without_canceling_invoice_or_creating_link_between_notes(): void
    {
        [$invoice, $document] = $this->context(DocumentModel::NFSE);
        $page = Livewire::test(EditInvoice::class, ['record' => $invoice->id])->assertActionHidden('generateNfseDocument');
        $service = $this->mock(NfseDocumentService::class);
        $service->shouldReceive('cancelar')->once()->andReturnUsing(function ($record): bool {
            $record->update(['nfse_status' => NfeStatus::CANCELED, 'status' => Status::CANCELLED, 'canceled_at' => now()]);

            return true;
        });
        $service->shouldReceive('isSuccess')->andReturnTrue();
        $service->shouldReceive('getMessage')->andReturn('NFS-e cancelada.');
        Livewire::test(FiscalDocumentsRelationManager::class, ['ownerRecord' => $invoice, 'pageClass' => EditInvoice::class])
            ->assertTableActionVisible('cancelar_nfse', $document)
            ->assertTableActionHidden('corrigir_nfe', $document)
            ->callTableAction('cancelar_nfse', $document, ['codigo_cancelamento' => '9', 'motivo_cancelamento' => 'Cancelamento solicitado pela empresa'])
            ->assertHasNoActionErrors()->assertDispatched('invoice-fiscal-state-changed');
        $page->call('refreshInvoiceState')->assertActionVisible('generateNfseDocument')->assertActionEnabled('generateNfseDocument')
            ->callAction('generateNfseDocument', data: ['nfse_model' => 'municipal', 'issued_at' => today()->toDateString(), 'nfse_item_description' => 'Descrição livre definida pela empresa para a nova nota.', 'open_document_after_confirm' => false])
            ->assertHasNoActionErrors();
        $this->assertSame(2, $invoice->fiscalDocuments()->count());
        $this->assertSame(InvoiceStatus::CONFIRMED, $invoice->fresh()->status);
        $this->assertFalse($invoice->fresh()->canceled);
        $this->assertSame(0, $invoice->accountReceivables()->count());
        $new = $invoice->fiscalDocuments()->latest('id')->firstOrFail();
        $this->assertSame($invoice->id, $new->invoice_id);
        $this->assertSame('Descrição livre definida pela empresa para a nova nota.', $new->items()->firstOrFail()->description);
    }

    public function test_expired_document_exposes_admin_treatment_in_both_places_and_member_cannot_use_it(): void
    {
        [$invoice, $document] = $this->context();
        $document->update(['authorized_at' => now()->subDays(2)]);
        Livewire::test(EditFiscalDocument::class, ['record' => $document->id])->assertActionVisible('tratar_pendencia_fiscal');
        Livewire::test(FiscalDocumentsRelationManager::class, ['ownerRecord' => $invoice, 'pageClass' => EditInvoice::class])
            ->callTableAction('tratar_pendencia_fiscal', $document, ['resolution' => 'follow_up', 'notes' => 'Administrador acionou a contabilidade para tratamento fiscal.'])
            ->assertHasNoActionErrors();
        $this->assertSame('open', $document->fiscalIssues()->sole()->status);
        $member = User::factory()->create();
        $member->companies()->attach($document->company_id, ['role' => 'member', 'is_active' => true]);
        $this->actingAs($member);
        Livewire::test(FiscalDocumentsRelationManager::class, ['ownerRecord' => $invoice, 'pageClass' => EditInvoice::class])
            ->assertTableActionHidden('tratar_pendencia_fiscal', $document);
    }

    private function context(DocumentModel $type = DocumentModel::NFE): array
    {
        $order = ServiceOrder::factory()->create(['status' => State::CLOSED]);
        $owner = User::findOrFail($order->created_by);
        $owner->companies()->attach($order->company_id, ['role' => 'owner', 'is_active' => true]);
        $order->customer->companies()->attach($order->company_id, ['is_active' => true, 'type' => '["customer"]']);
        $invoice = Invoice::create(['company_id' => $order->company_id, 'customer_id' => $order->customer_id, 'invoice_number' => 'ACTIONS-001', 'invoice_date' => today(), 'status' => InvoiceStatus::PENDING, 'created_by' => $owner->id]);
        $order->update(['invoice_id' => $invoice->id, 'status' => State::INVOICED]);
        $service = Service::factory()->create(['company_id' => $invoice->company_id]);
        ServiceOrderItem::create(['service_order_id' => $order->id, 'service_id' => $service->id, 'quantity' => 1, 'unit_price' => 100, 'discount_amount' => 0, 'created_by' => $owner->id]);
        $invoice->update(['status' => InvoiceStatus::CONFIRMED]);
        $document = FiscalDocument::create([
            'company_id' => $invoice->company_id, 'customer_id' => $invoice->customer_id, 'invoice_id' => $invoice->id,
            'document_type' => $type, 'operation_type' => '1', 'status' => Status::CONFIRMED,
            'issued_at' => today(), 'movement_at' => today(), 'document_key' => fake()->numerify('############################################'),
            $type === DocumentModel::NFE ? 'nfe_status' : 'nfse_status' => NfeStatus::AUTHORIZED,
            'authorized_at' => now(), 'created_by' => $owner->id, 'nfse_model' => 'municipal',
        ]);
        $this->actingAs($owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($order->company);

        return [$invoice, $document];
    }
}
