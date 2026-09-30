<?php

namespace Tests\Feature\Filament\Invoices;

use App\Filament\Clusters\Financial\Resources\BankSlips\BankSlipResource;
use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BankSlipFilamentTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_open_the_bank_slips_resource_for_the_current_company(): void
    {
        $user = User::factory()->create();
        $company = Company::query()->create([
            'name' => 'Empresa de boletos',
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

        $this->get(BankSlipResource::getUrl('index'))->assertOk();
    }
}
