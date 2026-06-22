<?php

namespace Tests\Feature\Console;

use App\Models\Company;
use App\Models\CompanyPartner;
use App\Models\Partner;
use App\Models\TemporaryPartnerMigrationLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ImportTemporaryMigrationPartnersCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_imports_partners_from_migration_api_and_associates_them_to_company(): void
    {
        config()->set('services.migration_api.base_url', 'https://legacy.example');
        config()->set('services.migration_api.key', 'secret');

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

        Http::fake([
            'https://legacy.example/api/migracao/parceiros*' => Http::sequence()
                ->push([
                    'data' => [
                        [
                            'legacy_id' => 10,
                            'nome' => 'Cliente Um',
                            'tipo_vinculo' => 'CLIENTE',
                            'tipo_documento' => 'CNPJ',
                            'nro_documento' => '12345678000199',
                            'ativo' => true,
                            'inscricao_estadual' => '123456789',
                            'created_at' => '2026-06-21T12:00:00.000000Z',
                            'updated_at' => '2026-06-21T12:00:00.000000Z',
                            'deleted_at' => null,
                        ],
                    ],
                    'meta' => [
                        'resource' => 'parceiros',
                        'count' => 1,
                        'limit' => 1,
                        'has_more' => true,
                        'next_after_id' => 10,
                        'filters' => [
                            'after_id' => 0,
                            'updated_from' => null,
                            'include_deleted' => true,
                        ],
                    ],
                ])
                ->push([
                    'data' => [
                        [
                            'legacy_id' => 11,
                            'nome' => 'Fornecedor Dois',
                            'tipo_vinculo' => 'FORNECEDOR',
                            'tipo_documento' => 'CPF',
                            'nro_documento' => '12345678900',
                            'ativo' => false,
                            'inscricao_estadual' => 'ISENTO',
                            'created_at' => '2026-06-21T12:00:00.000000Z',
                            'updated_at' => '2026-06-22T12:00:00.000000Z',
                            'deleted_at' => '2026-06-23T12:00:00.000000Z',
                        ],
                    ],
                    'meta' => [
                        'resource' => 'parceiros',
                        'count' => 1,
                        'limit' => 1,
                        'has_more' => false,
                        'next_after_id' => null,
                        'filters' => [
                            'after_id' => 10,
                            'updated_from' => null,
                            'include_deleted' => true,
                        ],
                    ],
                ]),
        ]);

        $this->artisan('migration:partners:import', [
            'company_id' => $company->id,
            'user_id' => $user->id,
            '--limit' => 1,
            '--include-deleted' => true,
        ])->assertExitCode(0);

        $this->assertDatabaseCount('partners', 2);
        $this->assertDatabaseCount('company_partner', 2);
        $this->assertDatabaseCount('temporary_partner_migration_links', 2);

        $customer = Partner::query()->where('document_number', '12.345.678/0001-99')->first();
        $supplier = Partner::withTrashed()->where('document_number', '123.456.789-00')->first();

        $this->assertNotNull($customer);
        $this->assertSame('cnpj', $customer->document_type);
        $this->assertSame('1', $customer->state_tax_indicator->value);

        $this->assertNotNull($supplier);
        $this->assertTrue($supplier->trashed());
        $this->assertSame('cpf', $supplier->document_type);
        $this->assertSame('2', $supplier->state_tax_indicator->value);

        $this->assertDatabaseHas('company_partner', [
            'company_id' => $company->id,
            'partner_id' => $customer->id,
            'is_active' => true,
        ]);

        $companyPartner = CompanyPartner::query()
            ->where('company_id', $company->id)
            ->where('partner_id', $supplier->id)
            ->first();

        $this->assertNotNull($companyPartner);
        $this->assertSame(['supplier'], $companyPartner->type);
        $this->assertFalse($companyPartner->is_active);
    }

    public function test_it_reuses_existing_partner_by_document_and_creates_temporary_link(): void
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
            'name' => 'Nome Antigo',
            'document_type' => 'cpf',
            'document_number' => '987.654.321-00',
            'state_tax_indicator' => '9',
            'created_by' => $user->id,
        ]);

        Http::fake([
            'https://legacy.example/api/migracao/parceiros*' => Http::response([
                'data' => [
                    [
                        'legacy_id' => 99,
                        'nome' => 'Nome Atualizado do Legado',
                        'tipo_vinculo' => 'CLIENTE',
                        'tipo_documento' => 'CPF',
                        'nro_documento' => '98765432100',
                        'ativo' => true,
                        'inscricao_estadual' => null,
                        'created_at' => '2026-06-21T12:00:00.000000Z',
                        'updated_at' => '2026-06-21T13:00:00.000000Z',
                        'deleted_at' => null,
                    ],
                ],
                'meta' => [
                    'resource' => 'parceiros',
                    'count' => 1,
                    'limit' => 500,
                    'has_more' => false,
                    'next_after_id' => null,
                    'filters' => [
                        'after_id' => 0,
                        'updated_from' => null,
                        'include_deleted' => false,
                    ],
                ],
            ]),
        ]);

        $this->artisan('migration:partners:import', [
            'company_id' => $company->id,
            'user_id' => $user->id,
        ])->assertExitCode(0);

        $this->assertDatabaseCount('partners', 1);

        $partner->refresh();

        $this->assertSame('Nome Atualizado do Legado', $partner->name);

        $link = TemporaryPartnerMigrationLink::query()
            ->where('company_id', $company->id)
            ->where('legacy_id', 99)
            ->first();

        $this->assertNotNull($link);
        $this->assertSame($partner->id, $link->partner_id);
    }
}
