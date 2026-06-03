<?php

namespace Tests\Feature\Filament\Management;

use App\Filament\Management\Resources\Companies\CompanyResource;
use App\Filament\Management\Resources\Users\UserResource;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManagementPanelAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_admin_can_access_management_panel(): void
    {
        $panel = Filament::getPanel('management');

        $admin = User::factory()->create(['is_admin' => true]);
        $user = User::factory()->create(['is_admin' => false]);

        $this->assertTrue($admin->canAccessPanel($panel));
        $this->assertFalse($user->canAccessPanel($panel));
    }

    public function test_admin_can_open_management_resources(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin);
        Filament::setCurrentPanel('management');

        $this->get(CompanyResource::getUrl('index'))->assertOk();
        $this->get(UserResource::getUrl('index'))->assertOk();
    }
}
