<?php

namespace App\Services\Fiscal\Sefaz;

use App\Models\Company;
use App\Services\Fiscal\NfeConfigService;
use App\Services\Fiscal\Sefaz\DTO\DfeDistributionDocument;
use App\Services\Fiscal\Sefaz\DTO\DfeDistributionResult;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Str;
use RuntimeException;

class SefazDfeDistributionService
{
    private const SOAP_NAMESPACE = 'http://schemas.xmlsoap.org/soap/envelope/';
    private const WSDL_NAMESPACE = 'http://www.portalfiscal.inf.br/nfe/wsdl/NFeDistribuicaoDFe';
    private const NFE_NAMESPACE = 'http://www.portalfiscal.inf.br/nfe';
    private const SOAP_ACTION = 'http://www.portalfiscal.inf.br/nfe/wsdl/NFeDistribuicaoDFe/nfeDistDFeInteresse';

    public function __construct(
        private readonly NfeConfigService $nfeConfigService,
        private readonly CompanySefazCertificateService $certificateService,
    ) {
    }

    public function distribute(Company $company, string $mode, string $value): DfeDistributionResult
    {
        $cnpj = preg_replace('/\D+/', '', (string) $company->document_number);
        if ($cnpj === null || strlen($cnpj) !== 14) {
            throw new RuntimeException('Informe um CNPJ válido para a empresa antes de consultar DF-e na SEFAZ.');
        }

        $environment = $this->nfeConfigService->resolveAmbiente($company->id);
        $certificate = $this->certificateService->loadForCompany($company);
        $authorUfCode = $this->resolveAuthorUfCode((string) data_get($company->address, 'state', ''));
        $requestXml = $this->buildSoapRequestXml($environment, $cnpj, $mode, $value, $authorUfCode);
        $responseXml = $this->sendSoapRequest(
            $this->resolveEndpoint($environment),
            $requestXml,
            $certificate['certificate_pem'],
            $certificate['private_key_pem'],
        );

        return $this->parseSoapResponse($responseXml);
    }

    public function buildSoapRequestXml(int $environment, string $cnpj, string $mode, string $value, string $authorUfCode): string
    {
        $cnpj = preg_replace('/\D+/', '', $cnpj) ?? '';
        $value = preg_replace('/\D+/', '', $value) ?? '';

        if (strlen($cnpj) !== 14) {
            throw new RuntimeException('CNPJ inválido para consulta DF-e.');
        }

        if ($value === '' || strlen($value) > 15) {
            throw new RuntimeException('NSU inválido para consulta DF-e.');
        }

        if (! in_array($mode, ['ultimo_nsu', 'numero_nsu'], true)) {
            throw new RuntimeException('Modo de consulta DF-e inválido.');
        }

        $normalizedValue = str_pad($value, 15, '0', STR_PAD_LEFT);
        $operationTag = $mode === 'ultimo_nsu' ? 'distNSU' : 'consNSU';
        $valueTag = $mode === 'ultimo_nsu' ? 'ultNSU' : 'NSU';

        $document = new DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = false;

        $envelope = $document->createElementNS(self::SOAP_NAMESPACE, 'soap:Envelope');
        $document->appendChild($envelope);
        $body = $document->createElementNS(self::SOAP_NAMESPACE, 'soap:Body');
        $envelope->appendChild($body);

        $request = $document->createElementNS(self::WSDL_NAMESPACE, 'nfeDistDFeInteresse');
        $body->appendChild($request);

        $dadosMsg = $document->createElementNS(self::WSDL_NAMESPACE, 'nfeDadosMsg');
        $request->appendChild($dadosMsg);

        $distribution = $document->createElementNS(self::NFE_NAMESPACE, 'distDFeInt');
        $distribution->setAttribute('versao', '1.01');
        $dadosMsg->appendChild($distribution);

        $distribution->appendChild($document->createElementNS(self::NFE_NAMESPACE, 'tpAmb', (string) $environment));
        $distribution->appendChild($document->createElementNS(self::NFE_NAMESPACE, 'cUFAutor', $authorUfCode));
        $distribution->appendChild($document->createElementNS(self::NFE_NAMESPACE, 'CNPJ', $cnpj));

        $operation = $document->createElementNS(self::NFE_NAMESPACE, $operationTag);
        $operation->appendChild($document->createElementNS(self::NFE_NAMESPACE, $valueTag, $normalizedValue));
        $distribution->appendChild($operation);

        return $document->saveXML() ?: '';
    }

    public function parseSoapResponse(string $responseXml): DfeDistributionResult
    {
        $document = new DOMDocument();
        if (! $document->loadXML($responseXml)) {
            throw new RuntimeException('A SEFAZ retornou um XML inválido na consulta DF-e.');
        }

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('soap', self::SOAP_NAMESPACE);
        $xpath->registerNamespace('nfe', self::NFE_NAMESPACE);

        /** @var DOMElement|null $distributionResponse */
        $distributionResponse = $xpath->query('//nfe:retDistDFeInt')->item(0);
        if (! $distributionResponse instanceof DOMElement) {
            throw new RuntimeException('A resposta da SEFAZ não contém o bloco retDistDFeInt.');
        }

        $statusCode = $this->stringValue($xpath, 'nfe:cStat', $distributionResponse);
        $statusMessage = $this->stringValue($xpath, 'nfe:xMotivo', $distributionResponse);
        $ultNsu = $this->normalizeDigits($this->stringValue($xpath, 'nfe:ultNSU', $distributionResponse));
        $maxNsu = $this->normalizeDigits($this->stringValue($xpath, 'nfe:maxNSU', $distributionResponse));

        $documents = [];
        /** @var DOMElement $docNode */
        foreach ($xpath->query('nfe:loteDistDFeInt/nfe:docZip', $distributionResponse) as $docNode) {
            $schema = trim((string) $docNode->getAttribute('schema'));
            $nsu = $this->normalizeDigits((string) $docNode->getAttribute('NSU')) ?? '';
            $encoded = trim($docNode->textContent);

            if ($encoded === '') {
                continue;
            }

            $decoded = base64_decode($encoded, true);
            if ($decoded === false) {
                continue;
            }

            $xml = @gzdecode($decoded);
            if ($xml === false || ! str_contains($xml, '<')) {
                continue;
            }

            $documents[] = new DfeDistributionDocument(
                nsu: $nsu,
                schema: $schema,
                xml: $xml,
                accessKey: $this->extractAccessKey($xml),
            );
        }

        return new DfeDistributionResult(
            success: in_array($statusCode, ['137', '138'], true),
            statusCode: $statusCode,
            statusMessage: $statusMessage,
            ultNsu: $ultNsu,
            maxNsu: $maxNsu,
            rawXml: $responseXml,
            documents: $documents,
        );
    }

    protected function sendSoapRequest(
        string $endpoint,
        string $requestXml,
        string $certificatePem,
        string $privateKeyPem,
    ): string {
        $certFile = tempnam(sys_get_temp_dir(), 'sefaz_cert_');
        $keyFile = tempnam(sys_get_temp_dir(), 'sefaz_key_');

        if ($certFile === false || $keyFile === false) {
            throw new RuntimeException('Não foi possível preparar arquivos temporários para o certificado A1.');
        }

        file_put_contents($certFile, $certificatePem);
        file_put_contents($keyFile, $privateKeyPem);

        $curl = curl_init($endpoint);

        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: text/xml; charset=utf-8',
                'SOAPAction: "' . self::SOAP_ACTION . '"',
                'Content-Length: ' . strlen($requestXml),
            ],
            CURLOPT_POSTFIELDS => $requestXml,
            CURLOPT_SSLCERT => $certFile,
            CURLOPT_SSLCERTTYPE => 'PEM',
            CURLOPT_SSLKEY => $keyFile,
            CURLOPT_SSLKEYTYPE => 'PEM',
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        ]);

        $response = curl_exec($curl);
        $errorMessage = curl_error($curl);
        $httpCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        @unlink($certFile);
        @unlink($keyFile);

        if ($response === false) {
            throw new RuntimeException('Falha técnica ao consultar DF-e na SEFAZ: ' . $errorMessage);
        }

        if ($httpCode >= 400) {
            throw new RuntimeException("A SEFAZ respondeu com HTTP {$httpCode} na consulta DF-e.");
        }

        return $response;
    }

    private function resolveEndpoint(int $environment): string
    {
        return $environment === NfeConfigService::AMBIENTE_PRODUCAO
            ? 'https://www1.nfe.fazenda.gov.br/NFeDistribuicaoDFe/NFeDistribuicaoDFe.asmx'
            : 'https://hom1.nfe.fazenda.gov.br/NFeDistribuicaoDFe/NFeDistribuicaoDFe.asmx';
    }

    private function resolveAuthorUfCode(string $state): string
    {
        $codes = [
            'RO' => '11', 'AC' => '12', 'AM' => '13', 'RR' => '14', 'PA' => '15', 'AP' => '16', 'TO' => '17',
            'MA' => '21', 'PI' => '22', 'CE' => '23', 'RN' => '24', 'PB' => '25', 'PE' => '26', 'AL' => '27',
            'SE' => '28', 'BA' => '29', 'MG' => '31', 'ES' => '32', 'RJ' => '33', 'SP' => '35', 'PR' => '41',
            'SC' => '42', 'RS' => '43', 'MS' => '50', 'MT' => '51', 'GO' => '52', 'DF' => '53',
        ];

        $normalizedState = Str::upper(trim($state));
        if (! isset($codes[$normalizedState])) {
            throw new RuntimeException('A empresa precisa ter uma UF válida no endereço para consultar DF-e na SEFAZ.');
        }

        return $codes[$normalizedState];
    }

    private function stringValue(DOMXPath $xpath, string $expression, DOMElement $context): string
    {
        $node = $xpath->query($expression, $context)->item(0);

        return $node instanceof DOMElement ? trim($node->textContent) : trim((string) $node?->nodeValue);
    }

    private function normalizeDigits(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $value) ?? '';

        return $digits !== '' ? $digits : null;
    }

    private function extractAccessKey(string $xml): ?string
    {
        if (preg_match('/\b\d{44}\b/', $xml, $matches) !== 1) {
            return null;
        }

        return $matches[0];
    }
}
