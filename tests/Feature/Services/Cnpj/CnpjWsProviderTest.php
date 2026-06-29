<?php

namespace Tests\Feature\Services\Cnpj;

use App\Domain\DTO\Cnpj\CnpjVO;
use App\Services\Cnpj\Providers\CnpjWsProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CnpjWsProviderTest extends TestCase
{
    public function test_normalizes_cnpj_ws_payload(): void
    {
        Http::fake([
            'https://publica.cnpj.ws/cnpj/*' => Http::response([
                'cnpj_raiz' => '12345678',
                'razao_social' => 'Empresa WS LTDA',
                'capital_social' => '15000.50',
                'natureza_juridica' => [
                    'id' => '2062',
                    'descricao' => 'Sociedade Empresaria Limitada',
                ],
                'simples' => [
                    'simples' => 'Sim',
                    'mei' => 'Nao',
                ],
                'estabelecimento' => [
                    'cnpj' => '12.345.678/0001-95',
                    'tipo' => 'MATRIZ',
                    'nome_fantasia' => 'Empresa WS',
                    'situacao_cadastral' => 'Ativa',
                    'data_situacao_cadastral' => '2024-01-01',
                    'data_inicio_atividade' => '2020-01-01',
                    'tipo_logradouro' => 'Rua',
                    'logradouro' => 'das Flores',
                    'numero' => '123',
                    'complemento' => 'Sala 1',
                    'bairro' => 'Centro',
                    'cep' => '01001-000',
                    'ddd1' => '11',
                    'telefone1' => '33334444',
                    'email' => 'contato@empresa.ws',
                    'atividade_principal' => [
                        'id' => '6201501',
                        'descricao' => 'Desenvolvimento de programas de computador sob encomenda',
                    ],
                    'atividades_secundarias' => [
                        [
                            'id' => '6202300',
                            'descricao' => 'Desenvolvimento e licenciamento de programas de computador customizaveis',
                        ],
                    ],
                    'estado' => [
                        'sigla' => 'SP',
                    ],
                    'cidade' => [
                        'nome' => 'Sao Paulo',
                        'ibge_id' => 3550308,
                    ],
                    'inscricoes_estaduais' => [
                        [
                            'inscricao_estadual' => '123.456.789.000',
                            'ativo' => true,
                            'estado' => [
                                'sigla' => 'SP',
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $provider = new CnpjWsProvider;
        $result = $provider->consult('12345678000195');

        $this->assertTrue($result->isSuccess());
        $this->assertSame('12345678000195', data_get($result->data, 'taxId'));
        $this->assertSame('Empresa WS LTDA', data_get($result->data, 'company.name'));
        $this->assertSame('Sociedade Empresaria Limitada', data_get($result->data, 'company.nature.text'));
        $this->assertSame(15000.50, data_get($result->data, 'company.equity'));
        $this->assertTrue(data_get($result->data, 'company.simples.optant'));
        $this->assertFalse(data_get($result->data, 'company.simei.optant'));
        $this->assertSame('Empresa WS', data_get($result->data, 'alias'));
        $this->assertSame('Rua das Flores', data_get($result->data, 'address.street'));
        $this->assertSame('Sao Paulo', data_get($result->data, 'address.city'));
        $this->assertSame('SP', data_get($result->data, 'address.state'));
        $this->assertSame(3550308, data_get($result->data, 'address.municipality'));
        $this->assertSame('6201501', (string) data_get($result->data, 'mainActivity.id'));
        $this->assertCount(1, data_get($result->data, 'sideActivities'));
        $this->assertSame('123456789000', data_get($result->data, 'registrations.0.number'));
        $this->assertTrue(data_get($result->data, 'registrations.0.enabled'));
        $this->assertSame('11', data_get($result->data, 'phones.0.area'));
        $this->assertSame('33334444', data_get($result->data, 'phones.0.number'));
        $this->assertSame('contato@empresa.ws', data_get($result->data, 'emails.0.address'));

        $normalized = CnpjVO::fromApiResponse($result->data)->toArray();

        $this->assertSame('123456789000', $normalized['state_tax_id']);
    }

    public function test_returns_null_state_tax_id_when_registration_is_missing(): void
    {
        Http::fake([
            'https://publica.cnpj.ws/cnpj/*' => Http::response([
                'razao_social' => 'Empresa Sem IE LTDA',
                'capital_social' => '1000.00',
                'natureza_juridica' => [
                    'descricao' => 'Sociedade Empresária Limitada',
                ],
                'simples' => null,
                'estabelecimento' => [
                    'cnpj' => '12.345.678/0001-95',
                    'tipo' => 'MATRIZ',
                    'nome_fantasia' => 'Empresa Sem IE',
                    'situacao_cadastral' => 'Ativa',
                    'data_situacao_cadastral' => '2024-01-01',
                    'data_inicio_atividade' => '2020-01-01',
                    'logradouro' => 'das Flores',
                    'numero' => '123',
                    'bairro' => 'Centro',
                    'cep' => '01001000',
                    'cidade' => [
                        'nome' => 'Sao Paulo',
                        'ibge_id' => 3550308,
                    ],
                    'estado' => [
                        'sigla' => 'SP',
                    ],
                    'atividade_principal' => [
                        'id' => '6201501',
                        'descricao' => 'Desenvolvimento',
                    ],
                    'inscricoes_estaduais' => [],
                ],
            ], 200),
        ]);

        $provider = new CnpjWsProvider();
        $result = $provider->consult('12345678000195');

        $this->assertTrue($result->isSuccess());

        $normalized = CnpjVO::fromApiResponse($result->data)->toArray();

        $this->assertNull($normalized['state_tax_id']);
    }
}
