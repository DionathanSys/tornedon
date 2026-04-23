<?php

namespace Tests\Feature\Services\FiscalDocument\Actions;

use App\Enum\FiscalDocument\DocumentModel;
use App\Enum\FiscalDocument\NfseModel;
use App\Enum\FiscalDocument\Status;
use App\Models\Company;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentItem;
use App\Models\Partner;
use App\Models\User;
use App\Services\FiscalDocument\Actions\BuildNfseMunicipalPayloadAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BuildNfseMunicipalPayloadActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_sends_unconditional_discount_and_calculates_iss_on_net_base(): void
    {
        $user = User::factory()->create();

        $company = Company::query()->create([
            'name' => 'Empresa Municipal',
            'document_number' => '12345678000199',
            'address' => ['city' => 'Chapeco', 'state' => 'SC', 'city_code' => '4204202'],
            'created_by' => $user->id,
        ]);

        $customer = Partner::query()->create([
            'name' => 'Cliente Municipal',
            'document_type' => 'CNPJ',
            'document_number' => '22345678000188',
            'created_by' => $user->id,
        ]);

        $document = FiscalDocument::query()->create([
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'status' => Status::PENDING->value,
            'document_type' => DocumentModel::NFSE->value,
            'nfse_model' => NfseModel::MUNICIPAL->value,
            'issued_at' => now()->toDateString(),
            'movement_at' => now()->toDateString(),
            'rps_number' => '10',
            'rps_series' => '1',
            'created_by' => $user->id,
        ]);

        FiscalDocumentItem::query()->create([
            'fiscal_document_id' => $document->id,
            'item_number' => 1,
            'description' => 'Servico com desconto',
            'quantity' => 1,
            'unit_of_measure' => 'UN',
            'unit_price' => 100,
            'total_price' => 100,
            'discount_amount' => 15,
            'municipal_tax_code' => '01.01',
            'nbs_code' => '123456789',
            'cnae_code' => '6201500',
            'iss_exigibility' => '1',
            'iss_rate' => 5,
            'included_in_total' => true,
            'created_by' => $user->id,
        ]);

        $payload = app(BuildNfseMunicipalPayloadAction::class)->execute($document->fresh('items', 'company', 'customer'));

        $this->assertNotNull($payload);
        $this->assertSame(100.0, (float) data_get($payload, 'servico.valor_servicos'));
        $this->assertSame(15.0, (float) data_get($payload, 'servico.valor_desconto_incondicionado'));
        $this->assertSame(85.0, (float) data_get($payload, 'servico.valor_base_calculo'));
        $this->assertNull(data_get($payload, 'servico.valor_desconto_condicionado'));
        $this->assertSame(15.0, (float) data_get($payload, 'servico.itens.0.valor_desconto_incondicionado'));
        $this->assertSame(85.0, (float) data_get($payload, 'servico.itens.0.valor_base_calculo'));
        $this->assertSame(4.25, (float) data_get($payload, 'servico.itens.0.valor_iss'));
    }
}
