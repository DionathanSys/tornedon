<?php

namespace Tests\Feature\Console;

use App\Models\Address;
use App\Models\Company;
use App\Models\CompanyPartner;
use App\Models\Contact;
use App\Models\Partner;
use App\Models\TemporaryPartnerMigrationLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ImportTemporaryMigrationAddressesAndContactsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_imports_addresses_and_contacts_for_mapped_partner(): void
    {
        config()->set('services.migration_api.base_url', 'https://legacy.example');

        $user = User::factory()->create();
        $company = Company::query()->create([
            'name' => 'Empresa Teste',
            'document_number' => '12345678000144',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'email' => 'empresa@example.com',
            'phone' => '11999999999',
            'is_active' => true,
            'created_by' => $user->id,
        ]);

        $partner = Partner::query()->create([
            'name' => 'Cliente Importado',
            'document_type' => 'cnpj',
            'document_number' => '12.345.678/0001-99',
            'state_tax_indicator' => '1',
            'created_by' => $user->id,
        ]);

        $companyPartner = CompanyPartner::query()->create([
            'company_id' => $company->id,
            'partner_id' => $partner->id,
            'type' => ['customer'],
            'invoice_threshold' => 0,
            'is_active' => true,
        ]);

        TemporaryPartnerMigrationLink::query()->create([
            'company_id' => $company->id,
            'legacy_id' => 12,
            'partner_id' => $partner->id,
            'company_partner_id' => $companyPartner->id,
            'legacy_document_number' => '12345678000199',
            'last_imported_at' => now(),
        ]);

        Http::fake([
            'https://legacy.example/api/migracao/enderecos*' => Http::response([
                'data' => [[
                    'legacy_id' => 55,
                    'legacy_parceiro_id' => 12,
                    'rua' => 'RUA DAS FLORES',
                    'numero' => '123',
                    'complemento' => 'SALA 2',
                    'bairro' => 'CENTRO',
                    'codigo_municipio' => '4204202',
                    'cidade' => 'CHAPECO',
                    'estado' => 'SC',
                    'cep' => '89800000',
                    'pais' => 'BRASIL',
                    'created_at' => '2026-06-10T14:22:31.000000Z',
                    'updated_at' => '2026-06-12T09:11:05.000000Z',
                ]],
                'meta' => ['resource' => 'enderecos', 'count' => 1, 'limit' => 500, 'has_more' => false, 'next_after_id' => null, 'filters' => ['after_id' => 0, 'updated_from' => null, 'parceiro_id' => 12]],
            ]),
            'https://legacy.example/api/migracao/contatos*' => Http::response([
                'data' => [[
                    'legacy_id' => 77,
                    'legacy_parceiro_id' => 12,
                    'nome_contato' => 'JOAO SILVA',
                    'email' => 'contato@cliente.com.br',
                    'telefone_fixo' => '4933320000',
                    'telefone_cel' => '49999990000',
                    'envio_ordem' => true,
                    'created_at' => '2026-06-10T14:22:31.000000Z',
                    'updated_at' => '2026-06-12T09:11:05.000000Z',
                ]],
                'meta' => ['resource' => 'contatos', 'count' => 1, 'limit' => 500, 'has_more' => false, 'next_after_id' => null, 'filters' => ['after_id' => 0, 'updated_from' => null, 'parceiro_id' => 12]],
            ]),
        ]);

        $this->artisan('migration:addresses:import', ['company_id' => $company->id, 'user_id' => $user->id, '--parceiro-id' => 12])->assertExitCode(0);
        $this->artisan('migration:contacts:import', ['company_id' => $company->id, 'user_id' => $user->id, '--parceiro-id' => 12])->assertExitCode(0);

        $address = Address::query()->first();
        $contact = Contact::query()->first();

        $this->assertNotNull($address);
        $this->assertSame($companyPartner->id, $address->company_partner_id);
        $this->assertSame('89800-000', $address->postal_code);

        $this->assertNotNull($contact);
        $this->assertSame($companyPartner->id, $contact->company_partner_id);
        $this->assertSame('contato@cliente.com.br', $contact->email);
        $this->assertTrue($contact->notify);
    }
}
