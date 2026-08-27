<?php

namespace Tests\Feature\Services\Fiscal\Sefaz;

use App\Models\SefazDistributionDocument;
use App\Services\Fiscal\Sefaz\SefazDistributionDanfeService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SefazDistributionDanfeServiceTest extends TestCase
{
    public function test_it_requires_the_full_xml_before_generating_the_danfe(): void
    {
        $document = new SefazDistributionDocument([
            'full_xml_available' => false,
        ]);

        $this->expectExceptionMessage('O DANFE só pode ser gerado após o recebimento do XML completo.');

        app(SefazDistributionDanfeService::class)->render($document);
    }

    public function test_it_generates_a_pdf_from_the_stored_full_xml(): void
    {
        Storage::fake('local');

        $path = 'sefaz/distribution/company-1/35260412345678000199550010000003211000000321/full/test.xml';
        Storage::disk('local')->put($path, $this->fullXml());

        $document = new SefazDistributionDocument([
            'full_xml_available' => true,
            'full_xml_path' => $path,
        ]);

        $pdf = app(SefazDistributionDanfeService::class)->render($document);

        $this->assertStringStartsWith('%PDF-', $pdf);
    }

    private function fullXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<nfeProc xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00">
    <NFe>
        <infNFe Id="NFe35260412345678000199550010000003211000000321" versao="4.00">
            <ide>
                <cUF>35</cUF><cNF>00000032</cNF><natOp>VENDA DENTRO DO ESTADO</natOp><mod>55</mod><serie>1</serie><nNF>321</nNF>
                <dhEmi>2026-04-19T10:00:00-03:00</dhEmi><dhSaiEnt>2026-04-19T10:00:00-03:00</dhSaiEnt><tpNF>1</tpNF><idDest>1</idDest><cMunFG>3550308</cMunFG><tpImp>1</tpImp><tpEmis>1</tpEmis><cDV>1</cDV><tpAmb>1</tpAmb><finNFe>1</finNFe><indFinal>0</indFinal><indPres>0</indPres><procEmi>0</procEmi><verProc>1.0</verProc>
            </ide>
            <emit><CNPJ>12345678000199</CNPJ><xNome>Fornecedor Teste LTDA</xNome><xFant>Fornecedor</xFant><enderEmit><xLgr>Rua Teste</xLgr><nro>100</nro><xBairro>Centro</xBairro><cMun>3550308</cMun><xMun>Sao Paulo</xMun><UF>SP</UF><CEP>01001000</CEP><cPais>1058</cPais><xPais>BRASIL</xPais><fone>1133334444</fone></enderEmit><IE>123456789</IE><CRT>3</CRT></emit>
            <dest><CNPJ>22345678000188</CNPJ><xNome>Empresa Destinataria LTDA</xNome><enderDest><xLgr>Av. Destino</xLgr><nro>200</nro><xBairro>Centro</xBairro><cMun>3550308</cMun><xMun>Sao Paulo</xMun><UF>SP</UF><CEP>01001000</CEP><cPais>1058</cPais><xPais>BRASIL</xPais></enderDest><indIEDest>9</indIEDest></dest>
            <det nItem="1"><prod><cProd>P001</cProd><cEAN>SEM GTIN</cEAN><xProd>Produto Teste</xProd><NCM>84713012</NCM><CFOP>5102</CFOP><uCom>UN</uCom><qCom>2.0000</qCom><vUnCom>75.4950</vUnCom><vProd>150.99</vProd><cEANTrib>SEM GTIN</cEANTrib><uTrib>UN</uTrib><qTrib>2.0000</qTrib><vUnTrib>75.4950</vUnTrib><indTot>1</indTot></prod><imposto><vTotTrib>0.00</vTotTrib><ICMS><ICMS00><orig>0</orig><CST>00</CST><modBC>3</modBC><vBC>150.99</vBC><pICMS>18.00</pICMS><vICMS>27.18</vICMS></ICMS00></ICMS><PIS><PISAliq><CST>01</CST><vBC>150.99</vBC><pPIS>0.00</pPIS><vPIS>0.00</vPIS></PISAliq></PIS><COFINS><COFINSAliq><CST>01</CST><vBC>150.99</vBC><pCOFINS>0.00</pCOFINS><vCOFINS>0.00</vCOFINS></COFINSAliq></COFINS></imposto></det>
            <total><ICMSTot><vBC>150.99</vBC><vICMS>27.18</vICMS><vICMSDeson>0.00</vICMSDeson><vFCP>0.00</vFCP><vBCST>0.00</vBCST><vST>0.00</vST><vFCPST>0.00</vFCPST><vFCPSTRet>0.00</vFCPSTRet><vProd>150.99</vProd><vFrete>0.00</vFrete><vSeg>0.00</vSeg><vDesc>0.00</vDesc><vII>0.00</vII><vIPI>0.00</vIPI><vIPIDevol>0.00</vIPIDevol><vPIS>0.00</vPIS><vCOFINS>0.00</vCOFINS><vOutro>0.00</vOutro><vNF>150.99</vNF><vTotTrib>0.00</vTotTrib></ICMSTot></total>
            <transp><modFrete>9</modFrete></transp><pag><detPag><tPag>90</tPag><vPag>150.99</vPag></detPag></pag><infAdic><infCpl>Documento gerado para teste.</infCpl></infAdic>
        </infNFe>
    </NFe>
    <protNFe versao="4.00"><infProt><tpAmb>1</tpAmb><verAplic>1.0</verAplic><chNFe>35260412345678000199550010000003211000000321</chNFe><dhRecbto>2026-04-19T10:05:00-03:00</dhRecbto><nProt>135260000000001</nProt><digVal>AAAA</digVal><cStat>100</cStat><xMotivo>Autorizado o uso da NF-e</xMotivo></infProt></protNFe>
</nfeProc>
XML;
    }
}
