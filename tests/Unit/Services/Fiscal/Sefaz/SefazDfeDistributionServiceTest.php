<?php

namespace Tests\Unit\Services\Fiscal\Sefaz;

use App\Services\Fiscal\NfeConfigService;
use App\Services\Fiscal\Sefaz\CompanySefazCertificateService;
use App\Services\Fiscal\Sefaz\SefazDfeDistributionService;
use Tests\TestCase;

class SefazDfeDistributionServiceTest extends TestCase
{
    public function test_it_builds_dist_nsu_request_xml(): void
    {
        $service = new SefazDfeDistributionService(
            app(NfeConfigService::class),
            app(CompanySefazCertificateService::class),
        );

        $xml = $service->buildSoapRequestXml(2, '12345678000199', 'ultimo_nsu', '7', '35');

        $this->assertStringContainsString('<tpAmb>2</tpAmb>', $xml);
        $this->assertStringContainsString('<cUFAutor>35</cUFAutor>', $xml);
        $this->assertStringContainsString('<CNPJ>12345678000199</CNPJ>', $xml);
        $this->assertStringContainsString('<distNSU>', $xml);
        $this->assertStringContainsString('<ultNSU>000000000000007</ultNSU>', $xml);
    }

    public function test_it_builds_cons_nsu_request_xml(): void
    {
        $service = new SefazDfeDistributionService(
            app(NfeConfigService::class),
            app(CompanySefazCertificateService::class),
        );

        $xml = $service->buildSoapRequestXml(1, '12345678000199', 'numero_nsu', '15', '35');

        $this->assertStringContainsString('<tpAmb>1</tpAmb>', $xml);
        $this->assertStringContainsString('<consNSU>', $xml);
        $this->assertStringContainsString('<NSU>000000000000015</NSU>', $xml);
    }

    public function test_it_parses_successful_distribution_with_documents(): void
    {
        $service = new SefazDfeDistributionService(
            app(NfeConfigService::class),
            app(CompanySefazCertificateService::class),
        );

        $docXml = '<resNFe xmlns="http://www.portalfiscal.inf.br/nfe"><chNFe>35260412345678000199550010000003211000000321</chNFe></resNFe>';
        $responseXml = $this->makeSoapResponse('138', 'Documentos localizados', '000000000000125', '000000000000300', [
            [
                'nsu' => '000000000000125',
                'schema' => 'resNFe_v1.01.xsd',
                'xml' => $docXml,
            ],
        ]);

        $result = $service->parseSoapResponse($responseXml);

        $this->assertTrue($result->success);
        $this->assertSame('138', $result->statusCode);
        $this->assertSame('000000000000125', $result->ultNsu);
        $this->assertSame('000000000000300', $result->maxNsu);
        $this->assertCount(1, $result->documents);
        $this->assertSame('resNFe_v1.01.xsd', $result->documents[0]->schema);
        $this->assertSame('000000000000125', $result->documents[0]->nsu);
        $this->assertSame('35260412345678000199550010000003211000000321', $result->documents[0]->accessKey);
    }

    public function test_it_parses_empty_successful_distribution(): void
    {
        $service = new SefazDfeDistributionService(
            app(NfeConfigService::class),
            app(CompanySefazCertificateService::class),
        );

        $responseXml = $this->makeSoapResponse(
            '137',
            'Nenhum documento localizado',
            '000000000000200',
            '000000000000200',
            [],
        );

        $result = $service->parseSoapResponse($responseXml);

        $this->assertTrue($result->success);
        $this->assertSame('137', $result->statusCode);
        $this->assertSame('Nenhum documento localizado', $result->statusMessage);
        $this->assertCount(0, $result->documents);
    }

    public function test_it_parses_rejection_response(): void
    {
        $service = new SefazDfeDistributionService(
            app(NfeConfigService::class),
            app(CompanySefazCertificateService::class),
        );

        $responseXml = $this->makeSoapResponse(
            '589',
            'Rejeicao: Numero do NSU informado superior ao maior NSU da base de dados do Ambiente Nacional',
            '000000000000000',
            '000000000000010',
            [],
        );

        $result = $service->parseSoapResponse($responseXml);

        $this->assertFalse($result->success);
        $this->assertSame('589', $result->statusCode);
        $this->assertSame('000000000000010', $result->maxNsu);
    }

    public function test_it_ignores_invalid_doczip_payloads_while_parsing_response(): void
    {
        $service = new SefazDfeDistributionService(
            app(NfeConfigService::class),
            app(CompanySefazCertificateService::class),
        );

        $responseXml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
    <soap:Body>
        <nfeDistDFeInteresseResponse xmlns="http://www.portalfiscal.inf.br/nfe/wsdl/NFeDistribuicaoDFe">
            <nfeDistDFeInteresseResult>
                <retDistDFeInt xmlns="http://www.portalfiscal.inf.br/nfe" versao="1.01">
                    <tpAmb>2</tpAmb>
                    <verAplic>1.0</verAplic>
                    <cStat>138</cStat>
                    <xMotivo>Documentos localizados</xMotivo>
                    <dhResp>2026-04-19T10:00:00-03:00</dhResp>
                    <ultNSU>000000000000010</ultNSU>
                    <maxNSU>000000000000010</maxNSU>
                    <loteDistDFeInt>
                        <docZip NSU="000000000000010" schema="resNFe_v1.01.xsd">bm90LXppcA==</docZip>
                    </loteDistDFeInt>
                </retDistDFeInt>
            </nfeDistDFeInteresseResult>
        </nfeDistDFeInteresseResponse>
    </soap:Body>
</soap:Envelope>
XML;

        $result = $service->parseSoapResponse($responseXml);

        $this->assertTrue($result->success);
        $this->assertCount(0, $result->documents);
    }

    /**
     * @param  array<int,array{nsu:string,schema:string,xml:string}>  $documents
     */
    private function makeSoapResponse(string $statusCode, string $statusMessage, string $ultNsu, string $maxNsu, array $documents): string
    {
        $docZipXml = '';

        foreach ($documents as $document) {
            $docZipXml .= sprintf(
                '<docZip NSU="%s" schema="%s">%s</docZip>',
                $document['nsu'],
                $document['schema'],
                base64_encode(gzencode($document['xml'])),
            );
        }

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
    <soap:Body>
        <nfeDistDFeInteresseResponse xmlns="http://www.portalfiscal.inf.br/nfe/wsdl/NFeDistribuicaoDFe">
            <nfeDistDFeInteresseResult>
                <retDistDFeInt xmlns="http://www.portalfiscal.inf.br/nfe" versao="1.01">
                    <tpAmb>2</tpAmb>
                    <verAplic>1.0</verAplic>
                    <cStat>{$statusCode}</cStat>
                    <xMotivo>{$statusMessage}</xMotivo>
                    <dhResp>2026-04-19T10:00:00-03:00</dhResp>
                    <ultNSU>{$ultNsu}</ultNSU>
                    <maxNSU>{$maxNsu}</maxNSU>
                    <loteDistDFeInt>{$docZipXml}</loteDistDFeInt>
                </retDistDFeInt>
            </nfeDistDFeInteresseResult>
        </nfeDistDFeInteresseResponse>
    </soap:Body>
</soap:Envelope>
XML;
    }
}
