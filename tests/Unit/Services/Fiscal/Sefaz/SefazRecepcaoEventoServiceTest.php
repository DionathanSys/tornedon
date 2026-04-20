<?php

namespace Tests\Unit\Services\Fiscal\Sefaz;

use App\Services\Fiscal\NfeConfigService;
use App\Services\Fiscal\Sefaz\CompanySefazCertificateService;
use App\Services\Fiscal\Sefaz\SefazRecepcaoEventoService;
use Tests\TestCase;

class SefazRecepcaoEventoServiceTest extends TestCase
{
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
        $this->assertStringContainsString('<nfeRecepcaoEvento', $xml);
        $this->assertStringContainsString('35260412345678000199550010000003211000000321', $xml);
    }

    public function test_it_parses_successful_manifestation_response(): void
    {
        $service = new SefazRecepcaoEventoService(
            app(NfeConfigService::class),
            app(CompanySefazCertificateService::class),
        );

        $result = $service->parseSoapResponse(<<<XML
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

        $certificatePem = <<<PEM
-----BEGIN CERTIFICATE-----
VEVTVA==
-----END CERTIFICATE-----
PEM;

        return [$privateKeyPem, $certificatePem];
    }
}
