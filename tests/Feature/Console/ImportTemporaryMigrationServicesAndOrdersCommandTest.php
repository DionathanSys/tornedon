<?php

namespace Tests\Feature\Console;

use App\Models\Company;
use App\Models\CompanyPartner;
use App\Models\Equipment;
use App\Models\Partner;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderItem;
use App\Models\TemporaryEquipmentMigrationLink;
use App\Models\TemporaryPartnerMigrationLink;
use App\Models\TemporaryServiceMigrationLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ImportTemporaryMigrationServicesAndOrdersCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_imports_services_and_service_orders_with_items(): void
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

        $equipment = Equipment::query()->create([
            'name' => 'IMPRESSORA TERMICA',
            'owner_id' => $partner->id,
            'company_id' => $company->id,
            'type' => 'general_electronic',
            'mark' => 'EPSON',
            'model' => 'TMT20',
            'serial_number' => 'SN-ABC-001',
            'created_by' => $user->id,
        ]);

        TemporaryPartnerMigrationLink::query()->create([
            'company_id' => $company->id,
            'legacy_id' => 12,
            'partner_id' => $partner->id,
            'company_partner_id' => $companyPartner->id,
            'last_imported_at' => now(),
        ]);

        TemporaryEquipmentMigrationLink::query()->create([
            'company_id' => $company->id,
            'legacy_id' => 101,
            'legacy_partner_id' => 12,
            'equipment_id' => $equipment->id,
            'owner_partner_id' => $partner->id,
            'last_imported_at' => now(),
        ]);

        Http::fake([
            'https://legacy.example/api/migracao/servicos*' => Http::response([
                'data' => [
                    [
                        'legacy_id' => 8,
                        'nome' => 'TROCA DE CABECA',
                        'descricao' => 'SERVICO DE MANUTENCAO',
                        'valor_unitario' => 150.0,
                        'ativo' => true,
                        'imposto_servico_id' => 2,
                        'created_at' => '2026-06-10T14:22:31.000000Z',
                        'updated_at' => '2026-06-12T09:11:05.000000Z',
                        'deleted_at' => null,
                    ],
                    [
                        'legacy_id' => 11,
                        'nome' => 'TESTE ELETRONICO',
                        'descricao' => 'DIAGNOSTICO',
                        'valor_unitario' => 100.0,
                        'ativo' => true,
                        'imposto_servico_id' => 2,
                        'created_at' => '2026-06-10T14:22:31.000000Z',
                        'updated_at' => '2026-06-12T09:11:05.000000Z',
                        'deleted_at' => null,
                    ],
                ],
                'meta' => ['resource' => 'servicos', 'count' => 2, 'limit' => 500, 'has_more' => false, 'next_after_id' => null, 'filters' => ['after_id' => 0, 'updated_from' => null, 'include_deleted' => false, 'ativo' => true]],
            ]),
        ]);

        $this->artisan('migration:services:import', ['company_id' => $company->id, 'user_id' => $user->id])->assertExitCode(0);

        Http::fake([
            'https://legacy.example/api/migracao/ordens-servico*' => Http::response([
                'data' => [[
                    'legacy_id' => 320,
                    'legacy_parceiro_id' => 12,
                    'legacy_equipamento_id' => 101,
                    'legacy_fatura_id' => 44,
                    'placa' => null,
                    'data_ordem' => '2026-06-10',
                    'data_encerrado' => '2026-06-12',
                    'valor_total' => 350.0,
                    'desconto' => 20.0,
                    'prioridade' => 'NORMAL',
                    'tipo_manutencao' => 'CORRETIVA',
                    'status' => 'encerrada',
                    'status_processo' => 'finalizado',
                    'relato_cliente' => 'EQUIPAMENTO NAO LIGA',
                    'itens_recebidos' => 'FONTE E CABOS',
                    'path_pdf' => null,
                    'img_equipamento' => null,
                    'nota_entrada_id' => 15,
                    'nota_retorno_id' => null,
                    'observacao_geral' => 'TESTADO EM BANCADA',
                    'observacao_interna' => 'CLIENTE APROVOU',
                    'created_at' => '2026-06-10T14:22:31.000000Z',
                    'updated_at' => '2026-06-12T09:11:05.000000Z',
                    'itens' => [
                        [
                            'legacy_id' => 900,
                            'legacy_ordem_servico_id' => 320,
                            'legacy_servico_id' => 8,
                            'quantidade' => 1.0,
                            'valor_unitario' => 150.0,
                            'valor_total' => 150.0,
                            'desconto' => 0.0,
                            'observacao' => 'SEM GARANTIA DE PECAS',
                            'garantia' => false,
                        ],
                        [
                            'legacy_id' => 901,
                            'legacy_ordem_servico_id' => 320,
                            'legacy_servico_id' => 11,
                            'quantidade' => 2.0,
                            'valor_unitario' => 100.0,
                            'valor_total' => 200.0,
                            'desconto' => 20.0,
                            'observacao' => null,
                            'garantia' => true,
                        ],
                    ],
                ]],
                'meta' => ['resource' => 'ordens_servico', 'count' => 1, 'limit' => 200, 'has_more' => false, 'next_after_id' => null, 'filters' => ['after_id' => 0, 'updated_from' => null, 'parceiro_id' => 12, 'equipamento_id' => null, 'fatura_id' => null, 'status' => 'encerrada']],
            ]),
        ]);

        $this->artisan('migration:service-orders:import', ['company_id' => $company->id, 'user_id' => $user->id])->assertExitCode(0);

        $this->assertDatabaseCount('services', 2);
        $this->assertDatabaseCount('service_orders', 1);
        $this->assertDatabaseCount('service_order_items', 2);

        $service = Service::query()->where('service_code', '0008')->first();
        $order = ServiceOrder::query()->where('number', '320')->first();

        $this->assertNotNull($service);
        $this->assertNotNull($order);
        $this->assertSame('0008', $service->service_code);
        $this->assertSame(0.0, (float) $service->min_sale_price);
        $this->assertNull($service->tax_classification);
        $this->assertSame(['migration' => ['legacy_id' => 8]], $service->additional_info);
        $this->assertSame($partner->id, $order->customer_id);
        $this->assertSame($equipment->id, $order->equipment_id);
        $this->assertSame('encerrada', $order->status->value);
        $this->assertSame('normal', $order->priority->value);
        $this->assertSame('reparo', $order->type->value);

        $item = ServiceOrderItem::query()->where('service_order_id', $order->id)->where('service_id', $service->id)->first();
        $this->assertNotNull($item);
        $this->assertSame(1.0, (float) $item->quantity);
    }
}
