<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LogViewerAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_view_log_viewer(): void
    {
        $this->get('/log-viewer')->assertForbidden();
    }

    public function test_only_active_super_admin_can_view_log_viewer(): void
    {
        $superAdmin = User::factory()->create(['is_admin' => true]);
        $managementAdmin = User::factory()->create([
            'is_admin' => false,
            'management_role' => 'management_admin',
        ]);
        $inactiveSuperAdmin = User::factory()->create([
            'is_admin' => true,
            'is_active' => false,
        ]);

        $this->actingAs($superAdmin)
            ->get('/log-viewer')
            ->assertOk();

        $this->actingAs($managementAdmin)
            ->get('/log-viewer')
            ->assertForbidden();

        $this->actingAs($inactiveSuperAdmin)
            ->get('/log-viewer')
            ->assertForbidden();
    }
}
