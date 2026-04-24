<?php

namespace Tests\Feature\Console;

use App\Models\AuditEntry;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ArchiveAuditEntriesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_archives_entries_older_than_three_months_and_prunes_database(): void
    {
        Storage::fake('local');

        config()->set('audit.archive.disk', 'local');
        config()->set('audit.archive.path', 'audit-archives-test');
        config()->set('audit.archive.retention_months', 3);

        $user = User::factory()->create();
        $company = Company::query()->create([
            'name' => 'Empresa Arquivo Auditoria',
            'document_number' => '12345678000777',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'created_by' => $user->id,
        ]);

        $oldEntry = AuditEntry::query()->create([
            'company_id' => $company->id,
            'auditable_type' => 'requisition',
            'auditable_id' => 10,
            'actor_user_id' => $user->id,
            'actor_name' => $user->name,
            'source' => 'web',
            'event' => 'requisition.closed',
            'action' => 'closed',
            'summary' => 'Requisição encerrada',
            'before' => ['status' => 'open'],
            'after' => null,
            'diff' => ['status' => ['before' => 'open', 'after' => 'closed']],
            'metadata' => ['record_identifier' => 'REQ-10'],
            'occurred_at' => now()->subMonths(4),
        ]);

        $recentEntry = AuditEntry::query()->create([
            'company_id' => $company->id,
            'auditable_type' => 'requisition',
            'auditable_id' => 11,
            'actor_user_id' => $user->id,
            'actor_name' => $user->name,
            'source' => 'web',
            'event' => 'requisition.reopened',
            'action' => 'reopened',
            'summary' => 'Requisição reaberta',
            'before' => null,
            'after' => null,
            'diff' => ['status' => ['before' => 'closed', 'after' => 'open']],
            'metadata' => ['record_identifier' => 'REQ-11'],
            'occurred_at' => now()->subMonth(),
        ]);

        $this->artisan('audit:archive-prune')
            ->assertSuccessful();

        $this->assertDatabaseMissing('audit_entries', ['id' => $oldEntry->id]);
        $this->assertDatabaseHas('audit_entries', ['id' => $recentEntry->id]);

        $files = Storage::disk('local')->allFiles('audit-archives-test');

        $this->assertCount(1, $files);

        $contents = Storage::disk('local')->get($files[0]);

        $this->assertStringContainsString('"id":' . $oldEntry->id, $contents);
        $this->assertStringNotContainsString('"id":' . $recentEntry->id, $contents);
    }
}
