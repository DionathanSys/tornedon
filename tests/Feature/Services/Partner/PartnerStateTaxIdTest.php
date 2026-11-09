<?php

namespace Tests\Feature\Services\Partner;

use App\Models\User;
use App\Services\Partner\Actions\CreatePartner;
use App\Services\Partner\Actions\EditPartner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PartnerStateTaxIdTest extends TestCase
{
    use RefreshDatabase;

    public static function stateTaxIds(): array
    {
        return [
            'numeric' => ['110042490114'],
            'leading zeroes' => ['001234567890'],
            'formatted' => ['062.307.904/0081'],
            'rural producer' => ['P01100424'],
            'exempt' => ['ISENTO'],
            'not informed' => [null],
        ];
    }

    #[DataProvider('stateTaxIds')]
    public function test_creates_partner_preserving_state_tax_id(?string $stateTaxId): void
    {
        $user = User::factory()->create();
        $action = new CreatePartner($user->id);

        $partner = $action->execute($this->partnerData($stateTaxId));

        $this->assertNotNull($partner);
        $this->assertTrue($action->isSuccess());
        $this->assertSame($stateTaxId, $partner->fresh()->state_tax_id);
    }

    #[DataProvider('stateTaxIds')]
    public function test_edits_partner_preserving_state_tax_id(?string $stateTaxId): void
    {
        $user = User::factory()->create();
        $partner = (new CreatePartner($user->id))->execute($this->partnerData('123456789'));
        $this->assertNotNull($partner);

        $action = new EditPartner($user->id, $partner);
        $updatedPartner = $action->execute($this->partnerData($stateTaxId));

        $this->assertNotNull($updatedPartner);
        $this->assertTrue($action->isSuccess());
        $this->assertSame($stateTaxId, $updatedPartner->fresh()->state_tax_id);
    }

    private function partnerData(?string $stateTaxId): array
    {
        return [
            'name' => 'Parceiro Teste',
            'document_type' => 'cnpj',
            'document_number' => '12.345.678/0001-99',
            'state_tax_id' => $stateTaxId,
            'state_tax_indicator' => $stateTaxId === 'ISENTO' ? '2' : '1',
        ];
    }
}
