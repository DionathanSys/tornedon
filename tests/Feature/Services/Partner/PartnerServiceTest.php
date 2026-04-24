<?php

namespace Tests\Feature\Services\Partner;

use App\Models\User;
use App\Services\Partner\PartnerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PartnerServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_allows_creating_more_than_one_partner_with_the_same_name(): void
    {
        $user = User::factory()->create();
        $service = app(PartnerService::class);

        $firstPartner = $service->createPartner($user->id, [
            'name' => 'Parceiro Repetido',
            'document_type' => 'cpf',
            'document_number' => '123.456.789-00',
            'state_tax_indicator' => '9',
        ]);

        $secondPartner = $service->createPartner($user->id, [
            'name' => 'Parceiro Repetido',
            'document_type' => 'cpf',
            'document_number' => '987.654.321-00',
            'state_tax_indicator' => '9',
        ]);

        $this->assertNotNull($firstPartner);
        $this->assertNotNull($secondPartner);
        $this->assertNotSame($firstPartner->id, $secondPartner->id);
        $this->assertDatabaseCount('partners', 2);
        $this->assertSame(2, \App\Models\Partner::query()->where('name', 'Parceiro Repetido')->count());
    }
}
