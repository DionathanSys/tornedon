<?php

namespace Tests\Feature\Services\Attachments;

use App\Enums\AttachmentType;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\EditServiceOrder;
use App\Filament\RelationManagers\AttachmentsRelationManager;
use App\Models\Attachment;
use App\Models\Company;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Services\Attachments\AttachmentService;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public static function versionedAttachmentTypes(): array
    {
        return [
            'multiple' => [AttachmentType::SERVICE_PHOTO],
            'single_latest' => [AttachmentType::CONTRACT],
        ];
    }

    #[DataProvider('versionedAttachmentTypes')]
    public function test_version_keeps_increasing_when_latest_attachment_was_deleted(AttachmentType $type): void
    {
        $first = $this->service->upload($this->owner, UploadedFile::fake()->create('primeiro.pdf', 100), $type);
        $second = $this->service->upload($this->owner, UploadedFile::fake()->create('segundo.pdf', 100), $type);
        $this->service->delete($second);

        $third = $this->service->upload($this->owner, UploadedFile::fake()->create('terceiro.pdf', 100), $type);

        $this->assertSame(3, $third->version);
        $this->assertTrue($third->is_current);
        $this->assertSoftDeleted($second);
        $this->assertSame($type === AttachmentType::SERVICE_PHOTO, $first->fresh()->is_current);
    }

    #[DataProvider('versionedAttachmentTypes')]
    public function test_version_does_not_restart_when_all_attachments_were_deleted(AttachmentType $type): void
    {
        $first = $this->service->upload($this->owner, UploadedFile::fake()->create('primeiro.pdf', 100), $type);
        $second = $this->service->upload($this->owner, UploadedFile::fake()->create('segundo.pdf', 100), $type);
        $this->service->delete($first);
        $this->service->delete($second);

        $third = $this->service->upload($this->owner, UploadedFile::fake()->create('terceiro.pdf', 100), $type);

        $this->assertSame(3, $third->version);
        $this->assertSame(1, $this->owner->currentAttachments()->count());
        $this->assertSame(3, $this->owner->attachments()->withTrashed()->count());
    }

    public function test_failed_upload_removes_new_file_and_rolls_back_previous_current_version(): void
    {
        $existing = $this->service->upload($this->owner, UploadedFile::fake()->create('primeiro.pdf', 100), AttachmentType::CONTRACT);
        $before = Storage::disk('local')->allFiles();
        Attachment::creating(function (): void {
            throw new \RuntimeException('Falha simulada ao criar o anexo.');
        });

        try {
            $this->service->upload($this->owner, UploadedFile::fake()->create('segundo.pdf', 100), AttachmentType::CONTRACT);
            $this->fail('O upload deveria falhar.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Falha simulada ao criar o anexo.', $exception->getMessage());
        }

        $this->assertSame($before, Storage::disk('local')->allFiles());
        $this->assertTrue($existing->fresh()->is_current);
        $this->assertSame(1, $this->owner->attachments()->count());
    }

    public function test_repeating_idempotent_upload_after_deletion_does_not_reuse_the_unique_key(): void
    {
        $options = ['idempotency_key' => 'tx-deleted'];
        $existing = $this->service->upload($this->owner, UploadedFile::fake()->create('original.pdf', 100), AttachmentType::GENERIC, $options);
        $this->service->delete($existing);
        $before = Storage::disk('local')->allFiles();

        $repeated = $this->service->upload($this->owner, UploadedFile::fake()->create('repetido.pdf', 100), AttachmentType::GENERIC, $options);

        $this->assertSame($existing->id, $repeated->id);
        $this->assertTrue($repeated->trashed());
        $this->assertSame($before, Storage::disk('local')->allFiles());
        $this->assertSame(1, $this->owner->attachments()->withTrashed()->count());
    }

    public function test_admin_upload_after_deleting_a_photo_does_not_collide_with_deleted_version(): void
    {
        $deleted = $this->service->upload($this->owner, UploadedFile::fake()->image('antiga.jpg'), AttachmentType::SERVICE_PHOTO);
        $this->service->delete($deleted);
        Filament::setCurrentPanel('admin');
        Filament::setTenant($this->owner->company);

        $page = Livewire::test(AttachmentsRelationManager::class, ['ownerRecord' => $this->owner, 'pageClass' => EditServiceOrder::class])
            ->callAction(TestAction::make('create')->table(), data: [
                'type' => AttachmentType::SERVICE_PHOTO->value,
                'file' => [UploadedFile::fake()->image('nova.jpg')],
                'description' => 'Antes do serviço',
            ])->assertHasNoActionErrors();

        $this->assertSame(2, $this->owner->currentAttachments()->sole()->version);
        $attachment = $this->owner->currentAttachments()->sole();
        $this->assertSame('Antes do serviço', $attachment->description);
        $page->callAction(TestAction::make('editDescription')->table($attachment), data: [
            'description' => 'Depois do serviço',
        ])->assertHasNoActionErrors();
        $this->assertSame('Depois do serviço', $attachment->fresh()->description);
    }

    public function test_admin_upload_failure_shows_notification_instead_of_internal_server_error(): void
    {
        Filament::setCurrentPanel('admin');
        Filament::setTenant($this->owner->company);
        $service = $this->mock(AttachmentService::class);
        $service->shouldReceive('upload')->once()->andThrow(new \RuntimeException('Detalhes internos da falha.'));

        Livewire::test(AttachmentsRelationManager::class, ['ownerRecord' => $this->owner, 'pageClass' => EditServiceOrder::class])
            ->callAction(TestAction::make('create')->table(), data: [
                'type' => AttachmentType::SERVICE_PHOTO->value,
                'file' => [UploadedFile::fake()->image('foto.jpg')],
            ])->assertHasNoActionErrors()
            ->assertNotified('Não foi possível enviar todos os anexos.');

        $this->assertSame(0, $this->owner->attachments()->count());
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

    public function test_authenticated_user_can_preview_supported_attachment_inline()
    {
        $file = UploadedFile::fake()->create('preview.pdf', 100, 'application/pdf');
        $attachment = $this->service->upload($this->owner, $file, AttachmentType::GENERIC);

        $response = $this->get(route('attachments.preview', [
            'attachment' => $attachment->public_id,
        ]));

        $response
            ->assertOk()
            ->assertHeader('Content-Disposition', 'inline; filename=preview.pdf')
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_attachment_preview_view_contains_supported_attachment()
    {
        $file = UploadedFile::fake()->create('modal-preview.pdf', 100, 'application/pdf');
        $attachment = $this->service->upload($this->owner, $file, AttachmentType::GENERIC);

        $html = view('filament.actions.attachment-preview', [
            'record' => $attachment,
        ])->render();

        $this->assertStringContainsString('<iframe', $html);
        $this->assertStringContainsString('/attachments/'.$attachment->public_id.'/preview', $html);
    }
}
