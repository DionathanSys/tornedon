<?php

namespace Tests\Unit\Services\FiscalDocument\Validators;

use App\Services\FiscalDocument\Validators\NfseNacionalV1Validator;
use PHPUnit\Framework\TestCase;

class NfseNacionalV1ValidatorTest extends TestCase
{
    private NfseNacionalV1Validator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = new NfseNacionalV1Validator();
    }

    // ------------------------------------------------------------------
    // Valid payloads
    // ------------------------------------------------------------------

    public function test_valid_minimum_payload_passes(): void
    {
        $payload = $this->validPayload();

        $this->assertTrue($this->validator->passes($payload));
    }

    public function test_valid_payload_with_cpf_passes(): void
    {
        $payload = $this->validPayload();
        unset($payload['tomador']['cnpj']);
        $payload['tomador']['cpf'] = '12345678901';

        $this->assertTrue($this->validator->passes($payload));
    }

    public function test_valid_payload_with_optional_fields_passes(): void
    {
        $payload = $this->validPayload();
        $payload['data_competencia'] = '2026-05-05T10:30:00-03:00';
        $payload['regime_tributacao'] = '0';
        $payload['informacoes_complementares'] = 'Nota complementar de teste.';
        $payload['tomador']['im'] = '12345';
        $payload['tomador']['telefone'] = '1133334444';
        $payload['tomador']['email'] = 'test@example.com';
        $payload['tomador']['endereco']['complemento'] = 'Sala 1';
        $payload['tomador']['endereco']['codigo_municipio'] = '3550308';
        $payload['tomador']['endereco']['cep'] = '01001000';

        $this->assertTrue($this->validator->passes($payload));
    }

    // ------------------------------------------------------------------
    // Root-level failures
    // ------------------------------------------------------------------

    public function test_missing_data_emissao_fails(): void
    {
        $payload = $this->validPayload();
        unset($payload['data_emissao']);

        $errors = $this->validator->validate($payload);

        $this->assertArrayHasKey('data_emissao', $errors);
    }

    public function test_data_emissao_without_timezone_fails(): void
    {
        $payload = $this->validPayload();
        $payload['data_emissao'] = '2026-05-05T10:30:00';

        $errors = $this->validator->validate($payload);

        $this->assertArrayHasKey('data_emissao', $errors);
    }

    public function test_missing_numero_fails(): void
    {
        $payload = $this->validPayload();
        unset($payload['numero']);

        $errors = $this->validator->validate($payload);

        $this->assertArrayHasKey('numero', $errors);
    }

    public function test_numero_with_more_than_9_digits_fails(): void
    {
        $payload = $this->validPayload();
        $payload['numero'] = '1234567890'; // 10 digits

        $errors = $this->validator->validate($payload);

        $this->assertArrayHasKey('numero', $errors);
    }

    public function test_missing_serie_fails(): void
    {
        $payload = $this->validPayload();
        unset($payload['serie']);

        $errors = $this->validator->validate($payload);

        $this->assertArrayHasKey('serie', $errors);
    }

    public function test_serie_with_more_than_5_chars_fails(): void
    {
        $payload = $this->validPayload();
        $payload['serie'] = '123456';

        $errors = $this->validator->validate($payload);

        $this->assertArrayHasKey('serie', $errors);
    }

    public function test_tipo_emitente_not_1_fails(): void
    {
        $payload = $this->validPayload();
        $payload['tipo_emitente'] = '2';

        $errors = $this->validator->validate($payload);

        $this->assertArrayHasKey('tipo_emitente', $errors);
    }

    public function test_informacoes_complementares_over_2000_chars_fails(): void
    {
        $payload = $this->validPayload();
        $payload['informacoes_complementares'] = str_repeat('A', 2001);

        $errors = $this->validator->validate($payload);

        $this->assertArrayHasKey('informacoes_complementares', $errors);
    }

    // ------------------------------------------------------------------
    // Tomador failures
    // ------------------------------------------------------------------

    public function test_missing_cnpj_and_cpf_fails(): void
    {
        $payload = $this->validPayload();
        unset($payload['tomador']['cnpj']);

        $errors = $this->validator->validate($payload);

        $this->assertArrayHasKey('tomador.documento', $errors);
    }

    public function test_invalid_cnpj_length_fails(): void
    {
        $payload = $this->validPayload();
        $payload['tomador']['cnpj'] = '123456';

        $errors = $this->validator->validate($payload);

        $this->assertArrayHasKey('tomador.cnpj', $errors);
    }

    public function test_invalid_cpf_length_fails(): void
    {
        $payload = $this->validPayload();
        unset($payload['tomador']['cnpj']);
        $payload['tomador']['cpf'] = '123';

        $errors = $this->validator->validate($payload);

        $this->assertArrayHasKey('tomador.cpf', $errors);
    }

    public function test_missing_razao_social_fails(): void
    {
        $payload = $this->validPayload();
        unset($payload['tomador']['razao_social']);

        $errors = $this->validator->validate($payload);

        $this->assertArrayHasKey('tomador.razao_social', $errors);
    }

    public function test_razao_social_over_300_chars_fails(): void
    {
        $payload = $this->validPayload();
        $payload['tomador']['razao_social'] = str_repeat('A', 301);

        $errors = $this->validator->validate($payload);

        $this->assertArrayHasKey('tomador.razao_social', $errors);
    }

    public function test_tipo_destinatario_not_0_fails(): void
    {
        $payload = $this->validPayload();
        $payload['tomador']['tipo_destinatario'] = '1';

        $errors = $this->validator->validate($payload);

        $this->assertArrayHasKey('tomador.tipo_destinatario', $errors);
    }

    // ------------------------------------------------------------------
    // Endereço failures
    // ------------------------------------------------------------------

    public function test_missing_logradouro_fails(): void
    {
        $payload = $this->validPayload();
        unset($payload['tomador']['endereco']['logradouro']);

        $errors = $this->validator->validate($payload);

        $this->assertArrayHasKey('tomador.endereco.logradouro', $errors);
    }

    public function test_missing_endereco_numero_fails(): void
    {
        $payload = $this->validPayload();
        unset($payload['tomador']['endereco']['numero']);

        $errors = $this->validator->validate($payload);

        $this->assertArrayHasKey('tomador.endereco.numero', $errors);
    }

    public function test_missing_bairro_fails(): void
    {
        $payload = $this->validPayload();
        unset($payload['tomador']['endereco']['bairro']);

        $errors = $this->validator->validate($payload);

        $this->assertArrayHasKey('tomador.endereco.bairro', $errors);
    }

    public function test_missing_uf_fails(): void
    {
        $payload = $this->validPayload();
        unset($payload['tomador']['endereco']['uf']);

        $errors = $this->validator->validate($payload);

        $this->assertArrayHasKey('tomador.endereco.uf', $errors);
    }

    public function test_uf_ex_fails(): void
    {
        $payload = $this->validPayload();
        $payload['tomador']['endereco']['uf'] = 'EX';

        $errors = $this->validator->validate($payload);

        $this->assertArrayHasKey('tomador.endereco.uf', $errors);
    }

    public function test_invalid_codigo_municipio_fails(): void
    {
        $payload = $this->validPayload();
        $payload['tomador']['endereco']['codigo_municipio'] = '123'; // not 7 digits

        $errors = $this->validator->validate($payload);

        $this->assertArrayHasKey('tomador.endereco.codigo_municipio', $errors);
    }

    public function test_invalid_cep_fails(): void
    {
        $payload = $this->validPayload();
        $payload['tomador']['endereco']['cep'] = '123'; // not 8 digits

        $errors = $this->validator->validate($payload);

        $this->assertArrayHasKey('tomador.endereco.cep', $errors);
    }

    // ------------------------------------------------------------------
    // Serviço failures
    // ------------------------------------------------------------------

    public function test_missing_servico_codigo_fails(): void
    {
        $payload = $this->validPayload();
        unset($payload['servico']['codigo']);

        $errors = $this->validator->validate($payload);

        $this->assertArrayHasKey('servico.codigo', $errors);
    }

    public function test_missing_discriminacao_fails(): void
    {
        $payload = $this->validPayload();
        unset($payload['servico']['discriminacao']);

        $errors = $this->validator->validate($payload);

        $this->assertArrayHasKey('servico.discriminacao', $errors);
    }

    public function test_missing_codigo_nbs_fails(): void
    {
        $payload = $this->validPayload();
        unset($payload['servico']['codigo_nbs']);

        $errors = $this->validator->validate($payload);

        $this->assertArrayHasKey('servico.codigo_nbs', $errors);
    }

    public function test_codigo_nbs_not_9_digits_fails(): void
    {
        $payload = $this->validPayload();
        $payload['servico']['codigo_nbs'] = '12345'; // not 9 digits

        $errors = $this->validator->validate($payload);

        $this->assertArrayHasKey('servico.codigo_nbs', $errors);
    }

    public function test_valor_servicos_zero_fails(): void
    {
        $payload = $this->validPayload();
        $payload['servico']['valor_servicos'] = 0;

        $errors = $this->validator->validate($payload);

        $this->assertArrayHasKey('servico.valor_servicos', $errors);
    }

    public function test_valor_servicos_negative_fails(): void
    {
        $payload = $this->validPayload();
        $payload['servico']['valor_servicos'] = -1;

        $errors = $this->validator->validate($payload);

        $this->assertArrayHasKey('servico.valor_servicos', $errors);
    }

    // ------------------------------------------------------------------
    // Tributos failures
    // ------------------------------------------------------------------

    public function test_tipo_operacao_not_1_fails(): void
    {
        $payload = $this->validPayload();
        $payload['servico']['tributos_municipais']['tipo_operacao'] = '3';

        $errors = $this->validator->validate($payload);

        $this->assertArrayHasKey('servico.tributos_municipais.tipo_operacao', $errors);
    }

    public function test_aliquota_iss_over_100_fails(): void
    {
        $payload = $this->validPayload();
        $payload['servico']['tributos_municipais']['aliquota_iss'] = 101;

        $errors = $this->validator->validate($payload);

        $this->assertArrayHasKey('servico.tributos_municipais.aliquota_iss', $errors);
    }

    public function test_invalid_codigo_municipio_prestacao_fails(): void
    {
        $payload = $this->validPayload();
        $payload['servico']['endereco_local_prestacao']['codigo_municipio_prestacao'] = '123';

        $errors = $this->validator->validate($payload);

        $this->assertArrayHasKey('servico.endereco_local_prestacao.codigo_municipio_prestacao', $errors);
    }

    public function test_missing_cst_tributos_nacionais_fails(): void
    {
        $payload = $this->validPayload();
        unset($payload['servico']['tributos_nacionais']['cst']);

        $errors = $this->validator->validate($payload);

        $this->assertArrayHasKey('servico.tributos_nacionais.cst', $errors);
    }

    public function test_invalid_tipo_retencao_fails(): void
    {
        $payload = $this->validPayload();
        $payload['servico']['tributos_nacionais']['tipo_retencao'] = '5';

        $errors = $this->validator->validate($payload);

        $this->assertArrayHasKey('servico.tributos_nacionais.tipo_retencao', $errors);
    }

    // ------------------------------------------------------------------
    // Valid edge cases
    // ------------------------------------------------------------------

    public function test_data_emissao_with_z_timezone_passes(): void
    {
        $payload = $this->validPayload();
        $payload['data_emissao'] = '2026-05-05T10:30:00Z';

        $this->assertTrue($this->validator->passes($payload));
    }

    public function test_valid_7_digit_codigo_municipio_passes(): void
    {
        $payload = $this->validPayload();
        $payload['tomador']['endereco']['codigo_municipio'] = '3550308';

        $this->assertTrue($this->validator->passes($payload));
    }

    public function test_valid_8_digit_cep_passes(): void
    {
        $payload = $this->validPayload();
        $payload['tomador']['endereco']['cep'] = '01001000';

        $this->assertTrue($this->validator->passes($payload));
    }

    // ------------------------------------------------------------------
    // Fixture
    // ------------------------------------------------------------------

    private function validPayload(): array
    {
        return [
            'data_emissao'  => '2026-05-05T10:30:00-03:00',
            'numero'        => '123',
            'serie'         => '1',
            'tipo_emitente' => '1',
            'tomador'       => [
                'cnpj'              => '12345678000199',
                'razao_social'      => 'EMPRESA EXEMPLO LTDA',
                'tipo_destinatario' => '0',
                'endereco'          => [
                    'logradouro' => 'Rua Exemplo',
                    'numero'     => '100',
                    'bairro'     => 'Centro',
                    'uf'         => 'SP',
                ],
            ],
            'servico' => [
                'codigo'         => '010101',
                'discriminacao'  => 'Prestação de serviço conforme contrato.',
                'codigo_nbs'     => '123456789',
                'valor_servicos' => 100.0,
                'endereco_local_prestacao' => [
                    'codigo_municipio_prestacao' => '3550308',
                ],
                'tributos_municipais' => [
                    'tipo_operacao' => '1',
                    'aliquota_iss'  => 5.0,
                ],
                'tributos_nacionais' => [
                    'cst'           => '06',
                    'tipo_retencao' => '2',
                ],
            ],
        ];
    }
}
