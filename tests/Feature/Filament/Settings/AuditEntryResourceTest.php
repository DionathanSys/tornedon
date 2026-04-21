<?php

namespace Tests\Feature\Filament\Settings;

use App\Enum\Audit\AuditSource;
use App\Filament\Clusters\Settings\Resources\AuditEntries\AuditEntryResource;
use App\Models\AuditEntry;
use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuditEntryResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $compiledPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tornedon-audit-views-test-' . Str::uuid();
        File::ensureDirectoryExists($compiledPath);

        config(['view.compiled' => $compiledPath]);

        $bladeCompiler = app('blade.compiler');
        $reflection = new \ReflectionClass($bladeCompiler);
        $cachePath = $reflection->getProperty('cachePath');
        $cachePath->setAccessible(true);
        $cachePath->setValue($bladeCompiler, $compiledPath);
    }

    public function test_audit_resource_query_is_scoped_to_current_tenant(): void
    {
        [$user, $companyA] = $this->createAuthenticatedTenant(isAdmin: true);
        $companyB = $this->createCompany($user, 'Empresa Auditoria B', '12345678000122');

        $tenantEntry = $this->createAuditEntry($companyA, $user, 'cash_movement.created', 'created');
        $otherTenantEntry = $this->createAuditEntry($companyB, $user, 'cash_movement.updated', 'updated');

        Filament::setCurrentPanel('admin');
        Filament::setTenant($companyA);

        $ids = AuditEntryResource::getEloquentQuery()->pluck('id')->all();

        $this->assertContains($tenantEntry->id, $ids);
        $this->assertNotContains($otherTenantEntry->id, $ids);
    }

    public function test_audit_navigation_requires_management_access(): void
    {
        [$admin] = $this->createAuthenticatedTenant(isAdmin: true);
        $this->assertTrue($admin->canViewAuditLogs());
        $this->assertTrue(AuditEntryResource::shouldRegisterNavigation());

        [$user, $company] = $this->createAuthenticatedTenant(
            isAdmin: false,
            email: 'usuario-auditoria@example.com',
            documentNumber: '12345678000133',
        );
        Filament::setTenant($company);

        $this->assertFalse($user->canViewAuditLogs());
        $this->assertFalse(AuditEntryResource::shouldRegisterNavigation());
        $this->assertFalse($user->can('viewAny', AuditEntry::class));
    }

    private function createAuditEntry(Company $company, User $user, string $event, string $action): AuditEntry
    {
        return AuditEntry::query()->create([
            'company_id' => $company->id,
            'auditable_type' => 'service_order',
            'auditable_id' => random_int(1, 9999),
            'actor_user_id' => $user->id,
            'actor_name' => $user->name,
            'source' => AuditSource::WEB,
            'event' => $event,
            'action' => $action,
            'summary' => "Evento {$event}",
            'before' => null,
            'after' => ['status' => $action],
            'diff' => ['status' => ['after' => $action]],
            'metadata' => ['record_identifier' => "REG-{$action}"],
            'occurred_at' => now(),
        ]);
    }

    /**
     * @return array{User,Company}
     */
    private function createAuthenticatedTenant(
        bool $isAdmin,
        string $email = 'admin-auditoria@example.com',
        string $documentNumber = '12345678000111',
    ): array
    {
        $user = User::factory()->create([
            'email' => $email,
            'is_admin' => $isAdmin,
        ]);

        $company = $this->createCompany($user, 'Empresa Auditoria', $documentNumber);

        $user->companies()->attach($company, [
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->actingAs($user);
        Filament::setCurrentPanel('admin');
        Filament::setTenant($company);

        return [$user, $company];
    }

    private function createCompany(User $user, string $name, string $documentNumber): Company
    {
        $suffix = substr($documentNumber, -4);

        return Company::query()->create([
            'name' => "{$name} {$suffix}",
            'document_number' => $documentNumber,
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'email' => Str::slug("{$name}-{$suffix}") . '@example.com',
            'is_active' => true,
            'created_by' => $user->id,
        ]);
    }
}
