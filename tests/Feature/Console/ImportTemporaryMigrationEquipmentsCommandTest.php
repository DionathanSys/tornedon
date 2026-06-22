<?php

namespace Tests\Feature\Console;

use App\Models\Company;
use App\Models\Equipment;
use App\Models\Partner;
use App\Models\TemporaryEquipmentMigrationLink;
use App\Models\TemporaryPartnerMigrationLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ImportTemporaryMigrationEquipmentsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_imports_equipments_and_binds_them_to_the_mapped_partner(): void
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

        TemporaryPartnerMigrationLink::query()->create([
            'company_id' => $company->id,
            'legacy_id' => 12,
            'partner_id' => $partner->id,
            'legacy_document_number' => '12345678000199',
            'last_imported_at' => now(),
        ]);

        Http::fake([
            'https://legacy.example/api/migracao/equipamentos*' => Http::sequence()
                ->push([
                    'data' => [
                        [
                            'legacy_id' => 101,
                            'legacy_parceiro_id' => 12,
                            'descricao' => 'IMPRESSORA TERMICA',
                            'nro_serie' => 'SN-ABC-001',
                            'modelo' => 'TMT20',
                            'marca' => 'EPSON',
                            'created_at' => '2026-06-10T14:22:31.000000Z',
                            'updated_at' => '2026-06-12T09:11:05.000000Z',
                            'deleted_at' => null,
                        ],
                    ],
                    'meta' => [
                        'resource' => 'equipamentos',
                        'count' => 1,
                        'limit' => 1,
                        'has_more' => true,
                        'next_after_id' => 101,
                        'filters' => [
                            'after_id' => 0,
                            'updated_from' => null,
                            'include_deleted' => true,
                            'parceiro_id' => 12,
                        ],
                    ],
                ])
                ->push([
                    'data' => [
                        [
                            'legacy_id' => 102,
                            'legacy_parceiro_id' => 12,
                            'descricao' => 'BALANCA',
                            'nro_serie' => 'SN-XYZ-900',
                            'modelo' => 'BCS21',
                            'marca' => 'TOLEDO',
                            'created_at' => '2026-06-10T14:25:10.000000Z',
                            'updated_at' => '2026-06-10T14:25:10.000000Z',
                            'deleted_at' => '2026-06-23T12:00:00.000000Z',
                        ],
                    ],
                    'meta' => [
                        'resource' => 'equipamentos',
                        'count' => 1,
                        'limit' => 1,
                        'has_more' => false,
                        'next_after_id' => null,
                        'filters' => [
                            'after_id' => 101,
                            'updated_from' => null,
                            'include_deleted' => true,
                            'parceiro_id' => 12,
                        ],
                    ],
                ]),
        ]);

        $this->artisan('migration:equipments:import', [
            'company_id' => $company->id,
            'user_id' => $user->id,
            '--limit' => 1,
            '--include-deleted' => true,
            '--parceiro-id' => 12,
        ])->assertExitCode(0);

        $this->assertDatabaseCount('equipments', 2);
        $this->assertDatabaseCount('temporary_equipment_migration_links', 2);

        $printer = Equipment::query()->where('serial_number', 'SN-ABC-001')->first();
        $scale = Equipment::withTrashed()->where('serial_number', 'SN-XYZ-900')->first();

        $this->assertNotNull($printer);
        $this->assertSame($partner->id, $printer->owner_id);
        $this->assertSame($company->id, $printer->company_id);
        $this->assertSame('general_electronic', $printer->type->value);

        $this->assertNotNull($scale);
        $this->assertTrue($scale->trashed());
        $this->assertSame('TOLEDO', $scale->mark);
    }

    public function test_it_reports_error_when_owner_partner_has_not_been_imported(): void
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

        Http::fake([
            'https://legacy.example/api/migracao/equipamentos*' => Http::response([
                'data' => [
                    [
                        'legacy_id' => 201,
                        'legacy_parceiro_id' => 999,
                        'descricao' => 'COLETOR',
                        'nro_serie' => 'MC-001',
                        'modelo' => 'MC3300',
                        'marca' => 'ZEBRA',
                        'created_at' => '2026-06-10T14:25:10.000000Z',
                        'updated_at' => '2026-06-10T14:25:10.000000Z',
                        'deleted_at' => null,
                    ],
                ],
                'meta' => [
                    'resource' => 'equipamentos',
                    'count' => 1,
                    'limit' => 500,
                    'has_more' => false,
                    'next_after_id' => null,
                    'filters' => [
                        'after_id' => 0,
                        'updated_from' => null,
                        'include_deleted' => false,
                        'parceiro_id' => null,
                    ],
                ],
            ]),
        ]);

        $this->artisan('migration:equipments:import', [
            'company_id' => $company->id,
            'user_id' => $user->id,
        ])->assertExitCode(0);

        $this->assertDatabaseCount('equipments', 0);
        $this->assertDatabaseCount('temporary_equipment_migration_links', 0);
    }
}
