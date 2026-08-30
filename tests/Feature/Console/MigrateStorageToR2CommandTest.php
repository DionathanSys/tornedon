<?php

namespace Tests\Feature\Console;

use App\Enums\AttachmentType;
use App\Models\Attachment;
use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MigrateStorageToR2CommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
        Storage::fake('r2');
        config(['uploads.logo_disk' => 'r2']);
    }

    public function test_it_migrates_attachments_and_company_logos_without_deleting_sources(): void
    {
        $company = $this->createCompany(['logo_path' => 'logos/company/logo.png']);
        $attachment = Attachment::query()->create([
            'attachable_type' => 'test',
            'attachable_id' => 1,
            'company_id' => $company->id,
            'type' => AttachmentType::GENERIC,
            'disk' => 'local',
            'path' => 'attachments/'.$company->id.'/manual.pdf',
            'original_name' => 'manual.pdf',
            'stored_name' => 'manual.pdf',
            'extension' => 'pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 7,
        ]);

        Storage::disk('local')->put($attachment->path, 'manual');
        Storage::disk('public')->put($company->logo_path, 'logo');

        $this->artisan('storage:migrate-to-r2')
            ->assertExitCode(0);

        $this->assertSame('r2', $attachment->fresh()->disk);
        Storage::disk('r2')->assertExists($attachment->path);
        Storage::disk('r2')->assertExists($company->logo_path);
        Storage::disk('local')->assertExists($attachment->path);
        Storage::disk('public')->assertExists($company->logo_path);
    }

    public function test_dry_run_does_not_copy_files_or_update_attachment_records(): void
    {
        $company = $this->createCompany();
        $attachment = Attachment::query()->create([
            'attachable_type' => 'test',
            'attachable_id' => 1,
            'company_id' => $company->id,
            'type' => AttachmentType::GENERIC,
            'disk' => 'local',
            'path' => 'attachments/'.$company->id.'/manual.pdf',
            'original_name' => 'manual.pdf',
            'stored_name' => 'manual.pdf',
            'extension' => 'pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 7,
        ]);

        Storage::disk('local')->put($attachment->path, 'manual');

        $this->artisan('storage:migrate-to-r2 --dry-run')
            ->assertExitCode(0);

        $this->assertSame('local', $attachment->fresh()->disk);
        Storage::disk('r2')->assertMissing($attachment->path);
    }

    private function createCompany(array $attributes = []): Company
    {
        return Company::query()->create([
            'name' => 'Empresa Teste '.uniqid(),
            'document_number' => '12345678000199',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            ...$attributes,
        ]);
    }
}
