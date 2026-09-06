<?php

namespace Tests\Feature\Services\Attachments;

use App\Enums\AttachmentType;
use App\Models\Attachment;
use App\Models\Company;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Services\Attachments\AttachmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AttachmentServiceTest extends TestCase
{
    use RefreshDatabase;

    private AttachmentService $service;

    private ServiceOrder $owner;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config(['attachments.default_disk' => 'local']);
        $this->service = app(AttachmentService::class);

        $company = Company::factory()->create();
        $this->user = User::factory()->create();
        $this->user->companies()->attach($company, [
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->actingAs($this->user);

        // Use a model that uses HasAttachments
        $this->owner = ServiceOrder::factory()->create(['company_id' => $company->id]);

        Storage::fake('local');
    }

    public function test_can_upload_new_attachment()
    {
        $file = UploadedFile::fake()->create('document.pdf', 100);

        $attachment = $this->service->upload($this->owner, $file, AttachmentType::GENERIC);

        $this->assertInstanceOf(Attachment::class, $attachment);
        $this->assertEquals('document.pdf', $attachment->original_name);
        $this->assertEquals(AttachmentType::GENERIC, $attachment->type);
        $this->assertTrue($attachment->is_current);
        $this->assertEquals(1, $attachment->version);

        Storage::disk('local')->assertExists($attachment->path);
    }

    public function test_single_latest_mode_replaces_current_attachment()
    {
        // Use CONTRACT which is configured as single_latest
        $file1 = UploadedFile::fake()->create('doc1.pdf', 100);
        $attachment1 = $this->service->upload($this->owner, $file1, AttachmentType::CONTRACT);

        $this->assertTrue($attachment1->is_current);
        $this->assertEquals(1, $attachment1->version);

        $file2 = UploadedFile::fake()->create('doc2.pdf', 100);
        $attachment2 = $this->service->upload($this->owner, $file2, AttachmentType::CONTRACT);

        $attachment1->refresh();

        $this->assertFalse($attachment1->is_current);
        $this->assertTrue($attachment2->is_current);
        $this->assertEquals(2, $attachment2->version);
    }

    public function test_multiple_mode_keeps_all_current()
    {
        // SERVICE_PHOTO is configured as multiple
        $file1 = UploadedFile::fake()->image('photo1.jpg');
        $attachment1 = $this->service->upload($this->owner, $file1, AttachmentType::SERVICE_PHOTO);

        $file2 = UploadedFile::fake()->image('photo2.jpg');
        $attachment2 = $this->service->upload($this->owner, $file2, AttachmentType::SERVICE_PHOTO);

        $attachment1->refresh();

        $this->assertTrue($attachment1->is_current);
        $this->assertTrue($attachment2->is_current);
        $this->assertEquals(1, $attachment1->version);
        $this->assertEquals(2, $attachment2->version);
    }

    public function test_idempotency_prevents_duplicate_uploads()
    {
        $file = UploadedFile::fake()->create('doc.pdf', 100);

        $attachment1 = $this->service->upload($this->owner, $file, AttachmentType::GENERIC, [
            'idempotency_key' => 'tx-123',
        ]);

        $attachment2 = $this->service->upload($this->owner, $file, AttachmentType::GENERIC, [
            'idempotency_key' => 'tx-123',
        ]);

        // Should return the exact same record, not create a new one
        $this->assertEquals($attachment1->id, $attachment2->id);

        $count = Attachment::where('idempotency_key', 'tx-123')->count();
        $this->assertEquals(1, $count);
    }

    public function test_logical_delete()
    {
        $file = UploadedFile::fake()->create('doc.pdf', 100);
        $attachment = $this->service->upload($this->owner, $file, AttachmentType::GENERIC);

        $result = $this->service->delete($attachment);

        $this->assertTrue($result);
        $this->assertSoftDeleted($attachment);

        // Physical file should still exist
        Storage::disk('local')->assertExists($attachment->path);
    }

    public function test_physical_delete()
    {
        $file = UploadedFile::fake()->create('doc.pdf', 100);
        $attachment = $this->service->upload($this->owner, $file, AttachmentType::GENERIC);

        $path = $attachment->path;

        $result = $this->service->delete($attachment, ['force' => true]);

        $this->assertDatabaseMissing('attachments', ['id' => $attachment->id]);

        // Physical file should be deleted
        Storage::disk('local')->assertMissing($path);
    }

    public function test_download_response()
    {
        $file = UploadedFile::fake()->create('downloadable.pdf', 100);
        $attachment = $this->service->upload($this->owner, $file, AttachmentType::GENERIC);

        $response = $this->service->downloadResponse($attachment);

        $this->assertNotNull($response);
        $this->assertEquals('attachment; filename=downloadable.pdf', $response->headers->get('content-disposition'));
    }
}
