<?php

namespace Tests\Feature\Attachments;

use App\Enum\ProductionOrder\DestinationType as ProductionOrderDestinationType;
use App\Enum\ProductionOrder\Priority as ProductionOrderPriority;
use App\Enum\ProductionOrder\Status as ProductionOrderStatus;
use App\Enum\ServiceOrder\Priority as ServiceOrderPriority;
use App\Enum\ServiceOrder\State as ServiceOrderState;
use App\Enum\ServiceOrder\Type as ServiceOrderType;
use App\Models\Company;
use App\Models\OrderAttachment;
use App\Models\Partner;
use App\Models\ProductionOrder;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Services\Attachments\OrderAttachmentStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OrderAttachmentFeatureTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Company $company;
    private Partner $customer;
    private OrderAttachmentStorageService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['attachments.default_disk' => 'local']);

        $this->service = app(OrderAttachmentStorageService::class);
        $this->user = User::factory()->create();

        $this->company = Company::create([
            'name' => 'Empresa Teste ' . uniqid(),
            'document_number' => '123456780001' . random_int(10, 99),
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'created_by' => $this->user->id,
        ]);

        $this->user->companies()->attach($this->company->id, [
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->customer = Partner::create([
            'name' => 'Cliente Teste ' . uniqid(),
            'document_type' => 'CPF',
            'document_number' => '123456789' . random_int(10, 99),
            'created_by' => $this->user->id,
        ]);
    }

    public function test_service_order_and_production_order_expose_polymorphic_attachments(): void
    {
        $serviceOrder = $this->createServiceOrder();
        $productionOrder = $this->createProductionOrder();

        $serviceOrderAttachment = $this->createAttachmentFor($serviceOrder, 'service-order.pdf');
        $productionOrderAttachment = $this->createAttachmentFor($productionOrder, 'production-order.pdf');

        $this->assertTrue($serviceOrder->fresh()->attachments->contains('id', $serviceOrderAttachment->id));
        $this->assertTrue($productionOrder->fresh()->attachments->contains('id', $productionOrderAttachment->id));
        $this->assertInstanceOf(ServiceOrder::class, $serviceOrderAttachment->fresh()->attachable);
        $this->assertInstanceOf(ProductionOrder::class, $productionOrderAttachment->fresh()->attachable);
    }

    public function test_service_stores_attachment_metadata_and_expected_directory_structure(): void
    {
        $serviceOrder = $this->createServiceOrder();
        $attachment = $this->createAttachmentFor($serviceOrder, 'manual.pdf');

        $this->assertNotNull($attachment);
        $this->assertSame('local', $attachment->disk);
        $this->assertSame('manual.pdf', $attachment->original_name);
        $this->assertNotNull($attachment->mime_type);
        $this->assertNotNull($attachment->size_bytes);
        $this->assertSame($this->user->id, $attachment->uploaded_by);
        $this->assertStringStartsWith(
            "attachments/{$this->company->id}/service-orders/{$serviceOrder->id}/",
            $attachment->path,
        );
        Storage::disk('local')->assertExists($attachment->path);
        $this->assertDatabaseHas('order_attachments', [
            'id' => $attachment->id,
            'disk' => 'local',
            'original_name' => 'manual.pdf',
            'uploaded_by' => $this->user->id,
        ]);
    }

    public function test_authenticated_user_from_same_company_can_download_attachment(): void
    {
        $attachment = $this->createAttachmentFor($this->createServiceOrder(), 'download.pdf');

        $response = $this
            ->actingAs($this->user)
            ->get(route('order-attachments.download', $attachment));

        $response->assertOk();
        $response->assertDownload('download.pdf');
    }

    public function test_download_is_forbidden_for_user_without_company_access(): void
    {
        $attachment = $this->createAttachmentFor($this->createServiceOrder(), 'private.pdf');
        $otherUser = User::factory()->create();

        $response = $this
            ->actingAs($otherUser)
            ->get(route('order-attachments.download', $attachment));

        $response->assertForbidden();
    }

    public function test_download_returns_not_found_when_file_is_missing(): void
    {
        $attachment = $this->createServiceOrder()->attachments()->create([
            'disk' => 'local',
            'path' => 'attachments/missing.pdf',
            'original_name' => 'missing.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 123,
            'uploaded_by' => $this->user->id,
        ]);

        $response = $this
            ->actingAs($this->user)
            ->get(route('order-attachments.download', $attachment));

        $response->assertNotFound();
    }

    public function test_deleting_attachment_removes_database_record_and_file(): void
    {
        $attachment = $this->createAttachmentFor($this->createServiceOrder(), 'delete-me.pdf');

        $this->assertTrue($this->service->delete($attachment));

        $this->assertDatabaseMissing('order_attachments', ['id' => $attachment->id]);
        Storage::disk('local')->assertMissing($attachment->path);
    }

    public function test_deleting_service_order_removes_its_attachments_and_files(): void
    {
        $serviceOrder = $this->createServiceOrder();
        $attachment = $this->createAttachmentFor($serviceOrder, 'service-delete.pdf');

        $serviceOrder->delete();

        $this->assertDatabaseMissing('order_attachments', ['id' => $attachment->id]);
        Storage::disk('local')->assertMissing($attachment->path);
    }

    public function test_deleting_production_order_removes_its_attachments_and_files(): void
    {
        $productionOrder = $this->createProductionOrder();
        $attachment = $this->createAttachmentFor($productionOrder, 'production-delete.pdf');

        $productionOrder->delete();

        $this->assertDatabaseMissing('order_attachments', ['id' => $attachment->id]);
        Storage::disk('local')->assertMissing($attachment->path);
    }

    private function createServiceOrder(): ServiceOrder
    {
        return ServiceOrder::create([
            'number' => 'OS-' . uniqid(),
            'customer_id' => $this->customer->id,
            'company_id' => $this->company->id,
            'order_date' => now()->toDateString(),
            'status' => ServiceOrderState::OPEN->value,
            'priority' => ServiceOrderPriority::NORMAL->value,
            'type' => ServiceOrderType::MAINTENANCE->value,
            'travel_value' => 0,
            'created_by' => $this->user->id,
        ]);
    }

    private function createProductionOrder(): ProductionOrder
    {
        return ProductionOrder::create([
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'status' => ProductionOrderStatus::QUEUED->value,
            'priority' => ProductionOrderPriority::NORMAL->value,
            'destination_type' => ProductionOrderDestinationType::STOCK->value,
            'created_by' => $this->user->id,
        ]);
    }

    private function createAttachmentFor(ServiceOrder|ProductionOrder $attachable, string $originalName): OrderAttachment
    {
        $file = UploadedFile::fake()->create($originalName, 12, 'application/pdf');
        $directory = $this->service->directoryFor($attachable);
        $storedName = $this->service->makeStoredFilename($originalName);
        $path = Storage::disk('local')->putFileAs($directory, $file, $storedName);

        $attachment = $this->service->create($attachable, [
            'path' => $path,
            'original_name' => $originalName,
        ], $this->user->id);

        $this->assertNotNull($attachment, $this->service->getMessageUser());

        return $attachment;
    }
}
