<?php

namespace Tests\Feature\Filament\Operation;

use App\Enum\Equipment\Type as EquipmentType;
use App\Enum\ServiceOrder\Priority;
use App\Enum\ServiceOrder\State;
use App\Enum\ServiceOrder\Type;
use App\Filament\Operation\Pages\ServiceOrders\ServiceOrderDetail;
use App\Filament\Operation\Pages\ServiceOrders\ServiceOrderQueue;
use App\Livewire\OperationAttachments;
use App\Models\Company;
use App\Models\CompanyPartner;
use App\Models\Equipment;
use App\Models\Partner;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderItem;
use App\Models\User;
use App\Services\Attachments\AttachmentService;
use App\Services\ServiceOrder\DuplicateServiceOrderWorkflow;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OperationServiceOrderFeaturesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $company;

    private Partner $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->company = Company::factory()->create(['created_by' => $this->user->id]);
        $this->user->companies()->attach($this->company, ['role' => 'admin', 'is_active' => true]);
        $this->actingAs($this->user);
        Filament::setCurrentPanel('operation');
        Filament::setTenant($this->company);
        $this->customer = Partner::query()->create(['name' => 'Cliente dos recursos da OS', 'document_type' => 'cpf', 'document_number' => '529.982.247-25', 'created_by' => $this->user->id]);
        CompanyPartner::query()->create(['company_id' => $this->company->id, 'partner_id' => $this->customer->id, 'type' => ['customer'], 'is_active' => true]);
        config(['attachments.default_disk' => 'local']);
        Storage::fake('local');
    }

    public function test_list_displays_and_searches_equipment_identifiers(): void
    {
        $vehicle = Equipment::query()->create(['name' => 'Descrição do veículo', 'type' => EquipmentType::CAR, 'placa' => 'ABC1D23', 'serial_number' => 'NAO-USAR-SERIAL', 'company_id' => $this->company->id, 'owner_id' => $this->customer->id, 'created_by' => $this->user->id]);
        $equipment = Equipment::query()->create(['name' => 'Descrição do equipamento', 'type' => EquipmentType::OTHER, 'serial_number' => 'SER-987', 'company_id' => $this->company->id, 'owner_id' => $this->customer->id, 'created_by' => $this->user->id]);
        $this->order('OS-VEICULO')->update(['equipment_id' => $vehicle->id]);
        $this->order('OS-EQUIPAMENTO')->update(['equipment_id' => $equipment->id]);

        Livewire::test(ServiceOrderQueue::class)->assertSee('ABC1D23')->assertSee('SER-987')
            ->assertDontSee('Descrição do veículo')->assertDontSee('Descrição do equipamento')->assertDontSee('NAO-USAR-SERIAL')
            ->set('search', 'ABC1D23')->assertSee('OS-VEICULO')->assertDontSee('OS-EQUIPAMENTO');
    }

    public function test_list_closes_an_order_through_the_existing_workflow(): void
    {
        $order = $this->order('OS-ENCERRAR');
        $this->item($order);
        Livewire::test(ServiceOrderQueue::class)->call('requestRowAction', 'close', $order->id)
            ->assertSet('showRowActionModal', true)->assertSet('selectedOrderNumber', $order->number)
            ->call('confirmRowAction')->assertSet('showRowActionModal', false)->assertDontSee('OS-ENCERRAR');
        $this->assertSame(State::CLOSED, $order->fresh()->status);
        $this->assertDatabaseHas('audit_entries', ['auditable_id' => $order->id, 'event' => 'service_order.closed']);
    }

    public function test_list_cancels_an_order_and_rechecks_status_at_confirmation(): void
    {
        $order = $this->order('OS-CANCELAR');
        Livewire::test(ServiceOrderQueue::class)->call('requestRowAction', 'cancel', $order->id)
            ->call('confirmRowAction')->assertSet('showRowActionModal', false)->assertDontSee('OS-CANCELAR');
        $this->assertSame(State::CANCELLED, $order->fresh()->status);

        $changed = $this->order('OS-MUDOU');
        $page = Livewire::test(ServiceOrderQueue::class)->call('requestRowAction', 'cancel', $changed->id);
        $changed->update(['status' => State::CLOSED]);
        $page->call('confirmRowAction')->assertSet('showRowActionModal', false);
        $this->assertSame(State::CLOSED, $changed->fresh()->status);
    }

    public function test_list_duplicates_order_data_and_services_into_a_new_open_order(): void
    {
        $source = $this->order('OS-DUPLICAR');
        $source->update(['status' => State::CLOSED, 'general_observations' => 'Observações copiadas', 'items_received' => 'Equipamento e cabo', 'customer_signature' => 'data:image/png;base64,'.base64_encode('signature'), 'customer_signed_at' => now(), 'customer_signed_by_name' => 'Cliente']);
        $item = $this->item($source);
        $page = Livewire::test(ServiceOrderQueue::class)->call('requestRowAction', 'duplicate', $source->id)->call('confirmRowAction');
        $duplicated = ServiceOrder::query()->whereKeyNot($source->id)->sole();

        $page->assertRedirect(ServiceOrderDetail::getUrl(['record' => $duplicated], tenant: $this->company));
        $this->assertSame($this->company->id, $duplicated->company_id);
        $this->assertNotSame($source->number, $duplicated->number);
        $this->assertSame(State::OPEN, $duplicated->status);
        $this->assertTrue($duplicated->order_date->isToday());
        $this->assertSame('Observações copiadas', $duplicated->general_observations);
        $this->assertSame('Equipamento e cabo', $duplicated->items_received);
        $this->assertNull($duplicated->customer_signature);
        $this->assertNull($duplicated->customer_signed_at);
        $this->assertNull($duplicated->invoice_id);
        $copy = $duplicated->items()->sole();
        $this->assertSame($item->service_id, $copy->service_id);
        $this->assertSame(150.0, (float) $copy->unit_price);
        $this->assertSame(270.0, (float) $copy->total_amount);
        $this->assertSame(State::CLOSED, $source->fresh()->status);
    }

    public function test_duplicate_workflow_rolls_back_if_copying_services_fails(): void
    {
        $source = $this->order('OS-DUPLICAR-FALHA');
        $this->item($source);
        ServiceOrderItem::creating(function ($item) use ($source): void {
            if ($item->service_order_id !== $source->id) {
                throw new \RuntimeException('Falha simulada na cópia');
            }
        });

        $workflow = app(DuplicateServiceOrderWorkflow::class);
        $this->assertNull($workflow->execute($source, $this->user->id));
        $this->assertTrue($workflow->hasError());
        $this->assertSame(1, ServiceOrder::query()->count());
        $this->assertSame(1, ServiceOrderItem::query()->count());
    }

    public static function rowActions(): array
    {
        return [['close'], ['cancel'], ['duplicate']];
    }

    #[DataProvider('rowActions')]
    public function test_list_actions_reject_orders_of_another_company(string $action): void
    {
        $otherCompany = Company::factory()->create(['created_by' => $this->user->id]);
        $foreign = $this->order('OS-OUTRA-EMPRESA', $otherCompany);
        Livewire::test(ServiceOrderQueue::class)->call('requestRowAction', $action, $foreign->id)->assertStatus(404);
        $this->assertSame(State::OPEN, $foreign->fresh()->status);
        $this->assertSame(1, ServiceOrder::query()->count());
    }

    public function test_attachments_can_be_uploaded_previewed_downloaded_and_removed(): void
    {
        $order = $this->order('OS-ANEXOS');
        $file = UploadedFile::fake()->createWithContent('documento.txt', 'Conteúdo privado do anexo');
        $page = Livewire::test(OperationAttachments::class, ['orderId' => $order->id])
            ->set('showUpload', true)->set('files', [$file])->call('uploadAttachments')->assertHasNoErrors()
            ->assertSet('showUpload', false)->assertSee('documento.txt');
        $attachment = $order->currentAttachments()->sole();
        $this->assertSame($this->company->id, $attachment->company_id);
        $this->assertSame($this->user->id, $attachment->uploaded_by);
        Storage::disk('local')->assertExists($attachment->path);
        $this->get($attachment->url)->assertOk()->assertDownload('documento.txt');
        $this->get($attachment->preview_url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');

        $page->call('viewAttachment', $attachment->id)->assertSet('showPreviewModal', true)
            ->assertSet('preview.url', $attachment->preview_url)
            ->call('requestAttachmentDeletion', $attachment->id)->assertSet('showDeleteModal', true)
            ->call('deleteAttachment')->assertSet('showDeleteModal', false)->assertDontSee('documento.txt');
        $this->assertSoftDeleted($attachment);
    }

    public function test_attachments_validate_type_and_keep_single_latest_version(): void
    {
        $order = $this->order('OS-ANEXOS-TIPO');
        Livewire::test(OperationAttachments::class, ['orderId' => $order->id])
            ->set('attachmentType', 'service_photo')->set('files', [UploadedFile::fake()->createWithContent('texto.txt', 'Texto que não é foto')])
            ->call('uploadAttachments')->assertHasErrors(['files.0']);
        $this->assertSame(0, $order->attachments()->count());

        $page = Livewire::test(OperationAttachments::class, ['orderId' => $order->id])->set('attachmentType', 'contract')
            ->set('files', [UploadedFile::fake()->createWithContent('contrato-1.pdf', "%PDF-1.4\nContrato\n%%EOF")])->call('uploadAttachments')->assertHasNoErrors();
        $first = $order->attachments()->sole();
        $page->set('files', [UploadedFile::fake()->createWithContent('contrato-2.pdf', "%PDF-1.4\nNovo contrato\n%%EOF")])->call('uploadAttachments')->assertHasNoErrors();
        $this->assertFalse($first->fresh()->is_current);
        $this->assertSame(1, $order->currentAttachments()->count());
        $this->assertSame('contrato-2.pdf', $order->currentAttachments()->sole()->original_name);
    }

    public function test_attachment_actions_reject_other_orders_and_other_companies(): void
    {
        $order = $this->order('OS-ANEXOS-ATUAL');
        $otherOrder = $this->order('OS-ANEXOS-OUTRA');
        $attachment = app(AttachmentService::class)->upload($otherOrder, UploadedFile::fake()->createWithContent('outra.txt', 'Outro arquivo'), 'generic');
        Livewire::test(OperationAttachments::class, ['orderId' => $order->id])->call('viewAttachment', $attachment->id)->assertStatus(404);
        Livewire::test(OperationAttachments::class, ['orderId' => $order->id])->call('requestAttachmentDeletion', $attachment->id)->assertStatus(404);
        $this->assertNotSoftDeleted($attachment);
        $foreign = $this->order('OS-ANEXOS-FORA', Company::factory()->create(['created_by' => $this->user->id]));
        Livewire::test(OperationAttachments::class, ['orderId' => $foreign->id])->assertStatus(404);
    }

    public function test_attendance_title_and_attachments_are_in_the_order_page(): void
    {
        $order = $this->order('OS-TITULO');
        Livewire::test(ServiceOrderDetail::class, ['record' => $order->id])->assertSee('Registros do Atendimento')->assertSee('Incluir anexos');
    }

    private function order(string $number, ?Company $company = null): ServiceOrder
    {
        return ServiceOrder::query()->create(['number' => $number, 'company_id' => ($company ?? $this->company)->id, 'customer_id' => $this->customer->id, 'created_by' => $this->user->id, 'updated_by' => $this->user->id, 'order_date' => now()->toDateString(), 'status' => State::OPEN, 'priority' => Priority::NORMAL, 'type' => Type::MAINTENANCE]);
    }

    private function item(ServiceOrder $order): ServiceOrderItem
    {
        $service = Service::factory()->create(['company_id' => $order->company_id, 'created_by' => $this->user->id, 'price' => 150]);

        return $order->items()->create(['service_id' => $service->id, 'quantity' => 2, 'unit_price' => 150, 'discount_percentage' => 10, 'discount_amount' => 30, 'created_by' => $this->user->id]);
    }
}
