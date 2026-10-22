<?php

namespace Tests\Unit\Services\Fiscal\Sefaz;

use App\Models\Company;
use App\Services\Fiscal\NfeConfigService;
use App\Services\Fiscal\Sefaz\CompanySefazCertificateService;
use App\Services\Fiscal\Sefaz\SefazRecepcaoEventoService;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SefazRecepcaoEventoServiceTest extends TestCase
{
    #[DataProvider('directManifestationCases')]
    public function test_it_manifests_directly_with_sefaz_using_the_company_certificate(
        int $environment,
        string $host,
        string $statusCode,
        bool $success,
    ): void {
        [$privateKeyPem, $certificatePem] = $this->generateCertificatePair();
        $company = new Company;
        $company->id = 42;
        $company->document_number = '12.345.678/0001-99';
        $accessKey = '35260412345678000199550010000003211000000321';

        $config = Mockery::mock(NfeConfigService::class);
        $config->shouldReceive('resolveAmbiente')->once()->with(42)->andReturn($environment);
        $config->shouldNotReceive('buildSdkParams');
        $config->shouldNotReceive('resolveToken');

        $certificates = Mockery::mock(CompanySefazCertificateService::class);
        $certificates->shouldReceive('loadForCompany')->once()->with($company)->andReturn([
            'certificate_pem' => $certificatePem,
            'private_key_pem' => $privateKeyPem,
        ]);

        $responseXml = <<<XML
<retEnvEvento xmlns="http://www.portalfiscal.inf.br/nfe" versao="1.00">
    <cStat>128</cStat>
    <xMotivo>Lote de evento processado</xMotivo>
    <retEvento versao="1.00">
        <infEvento>
            <cStat>{$statusCode}</cStat>
            <xMotivo>Resultado da SEFAZ</xMotivo>
            <nProt>135260000000001</nProt>
        </infEvento>
    </retEvento>
</retEnvEvento>
XML;

        /** @var SefazRecepcaoEventoService&\Mockery\MockInterface $service */
        $service = Mockery::mock(SefazRecepcaoEventoService::class, [$config, $certificates])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('sendSoapRequest')->once()->with(
            "https://{$host}/NFeRecepcaoEvento4/NFeRecepcaoEvento4.asmx",
            Mockery::on(fn (string $xml): bool => str_contains($xml, '<tpEvento>210210</tpEvento>')
                && str_contains($xml, '<CNPJ>12345678000199</CNPJ>')
                && str_contains($xml, "<tpAmb>{$environment}</tpAmb>")
                && str_contains($xml, "<chNFe>{$accessKey}</chNFe>")
                && str_contains($xml, '<nSeqEvento>2</nSeqEvento>')
                && str_contains($xml, '<Signature')),
            $certificatePem,
            $privateKeyPem,
        )->andReturn($responseXml);

        $result = $service->manifestScience($company, $accessKey, sequence: 2);

        $this->assertSame($success, $result['success']);
        $this->assertSame($statusCode, $result['event_status_code']);
        $this->assertSame('128', $result['batch_status_code']);
        $this->assertSame('Resultado da SEFAZ', $result['event_status_message']);
        $this->assertSame($responseXml, $result['raw_xml']);
    }

    public static function directManifestationCases(): array
    {
        return [
            'production' => [1, 'www.nfe.fazenda.gov.br', '135', true],
            'homologation' => [2, 'hom1.nfe.fazenda.gov.br', '135', true],
            'already registered' => [1, 'www.nfe.fazenda.gov.br', '573', true],
            'rejected event' => [1, 'www.nfe.fazenda.gov.br', '650', false],
        ];
    }

    public function test_it_builds_signed_manifestation_request_xml(): void
    {
        [$privateKeyPem, $certificatePem] = $this->generateCertificatePair();

        $service = new SefazRecepcaoEventoService(
            app(NfeConfigService::class),
            app(CompanySefazCertificateService::class),
        );

        $xml = $service->buildSoapRequestXml(
            environment: 2,
            cnpj: '12345678000199',
            accessKey: '35260412345678000199550010000003211000000321',
            privateKeyPem: $privateKeyPem,
            certificatePem: $certificatePem,
        );

        $this->assertStringContainsString('<tpEvento>210210</tpEvento>', $xml);
        $this->assertStringContainsString('<descEvento>Ciencia da Operacao</descEvento>', $xml);
        $this->assertStringContainsString('<Signature', $xml);
        $this->assertStringContainsString('<nfeRecepcaoEventoNF', $xml);
        $this->assertStringContainsString('35260412345678000199550010000003211000000321', $xml);
    }

    public function test_it_parses_successful_manifestation_response(): void
    {
        $service = new SefazRecepcaoEventoService(
            app(NfeConfigService::class),
            app(CompanySefazCertificateService::class),
        );

        $result = $service->parseSoapResponse(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
  <soap:Body>
    <nfeRecepcaoEventoNFResponse xmlns="http://www.portalfiscal.inf.br/nfe/wsdl/NFeRecepcaoEvento4">
      <nfeRecepcaoEventoNFResult>
        <retEnvEvento xmlns="http://www.portalfiscal.inf.br/nfe" versao="1.00">
          <idLote>20260419103000</idLote>
          <tpAmb>2</tpAmb>
          <verAplic>1.0</verAplic>
          <cOrgao>91</cOrgao>
          <cStat>128</cStat>
          <xMotivo>Lote de evento processado</xMotivo>
          <retEvento versao="1.00">
            <infEvento>
              <cOrgao>91</cOrgao>
              <tpAmb>2</tpAmb>
              <CNPJ>12345678000199</CNPJ>
              <chNFe>35260412345678000199550010000003211000000321</chNFe>
              <tpEvento>210210</tpEvento>
              <xEvento>Ciencia da Operacao</xEvento>
              <nSeqEvento>1</nSeqEvento>
              <cStat>135</cStat>
              <xMotivo>Evento registrado e vinculado a NF-e</xMotivo>
              <nProt>135260000000001</nProt>
            </infEvento>
          </retEvento>
        </retEnvEvento>
      </nfeRecepcaoEventoNFResult>
    </nfeRecepcaoEventoNFResponse>
  </soap:Body>
</soap:Envelope>
XML);

        $this->assertTrue($result['success']);
        $this->assertSame('128', $result['batch_status_code']);
        $this->assertSame('135', $result['event_status_code']);
        $this->assertSame('135260000000001', $result['protocol']);
    }

    /**
     * @return array{string,string}
     */
    private function generateCertificatePair(): array
    {
        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($resource === false) {
            $this->markTestSkipped('OpenSSL não conseguiu gerar uma chave privada no ambiente de teste.');
        }

        if (! openssl_pkey_export($resource, $privateKeyPem)) {
            $this->markTestSkipped('OpenSSL não conseguiu exportar a chave privada no ambiente de teste.');
        }

        $certificatePem = <<<'PEM'
-----BEGIN CERTIFICATE-----
VEVTVA==
-----END CERTIFICATE-----
PEM;

        return [$privateKeyPem, $certificatePem];
    }
}
