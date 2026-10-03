<?php

namespace Tests\Feature\Filament\Invoices;

use App\Filament\Clusters\Financial\Resources\PixCharges\PixChargeResource;
use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PixChargeFilamentTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_open_pix_charges_for_the_current_company(): void
    {
        $user = User::factory()->create();
        $company = Company::query()->create([
            'name' => 'Empresa PIX',
            'document_number' => '12345678000199',
            'address' => [],
            'is_active' => true,
            'created_by' => $user->id,
        ]);

        $user->companies()->attach($company, [
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->actingAs($user);
        Filament::setCurrentPanel('admin');
        Filament::setTenant($company);

        $this->get(PixChargeResource::getUrl('index'))->assertOk();
    }
}
