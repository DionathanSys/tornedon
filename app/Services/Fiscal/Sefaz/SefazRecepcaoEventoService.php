<?php

namespace App\Services\Fiscal\Sefaz;

use App\Models\Company;
use App\Services\Fiscal\NfeConfigService;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class SefazRecepcaoEventoService
{
    private const SOAP_NAMESPACE = 'http://schemas.xmlsoap.org/soap/envelope/';
    private const WSDL_NAMESPACE = 'http://www.portalfiscal.inf.br/nfe/wsdl/NFeRecepcaoEvento4';
    private const NFE_NAMESPACE = 'http://www.portalfiscal.inf.br/nfe';
    private const DS_NAMESPACE = 'http://www.w3.org/2000/09/xmldsig#';
    private const SOAP_ACTION = 'http://www.portalfiscal.inf.br/nfe/wsdl/NFeRecepcaoEvento4/nfeRecepcaoEvento';
    private const EVENT_TYPE_SCIENCE = '210210';

    public function __construct(
        private readonly NfeConfigService $nfeConfigService,
        private readonly CompanySefazCertificateService $certificateService,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function manifestScience(Company $company, string $accessKey, int $sequence = 1): array
    {
        $cnpj = preg_replace('/\D+/', '', (string) $company->document_number) ?? '';
        if (strlen($cnpj) !== 14) {
            throw new RuntimeException('Informe um CNPJ válido para a empresa antes de manifestar DF-e na SEFAZ.');
        }

        if (preg_match('/^\d{44}$/', $accessKey) !== 1) {
            throw new RuntimeException('A chave de acesso informada para manifestação é inválida.');
        }

        $environment = $this->nfeConfigService->resolveAmbiente($company->id);
        $certificate = $this->certificateService->loadForCompany($company);

        $requestXml = $this->buildSoapRequestXml(
            environment: $environment,
            cnpj: $cnpj,
            accessKey: $accessKey,
            privateKeyPem: $certificate['private_key_pem'],
            certificatePem: $certificate['certificate_pem'],
            sequence: $sequence,
        );

        $responseXml = $this->sendSoapRequest(
            endpoint: $this->resolveEndpoint($environment),
            requestXml: $requestXml,
            certificatePem: $certificate['certificate_pem'],
            privateKeyPem: $certificate['private_key_pem'],
        );

        return $this->parseSoapResponse($responseXml);
    }

    public function buildSoapRequestXml(
        int $environment,
        string $cnpj,
        string $accessKey,
        string $privateKeyPem,
        string $certificatePem,
        int $sequence = 1,
    ): string {
        $cnpj = preg_replace('/\D+/', '', $cnpj) ?? '';
        if (strlen($cnpj) !== 14) {
            throw new RuntimeException('CNPJ inválido para manifestação do destinatário.');
        }

        $id = sprintf('ID%s%s%02d', self::EVENT_TYPE_SCIENCE, $accessKey, $sequence);

        $document = new DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = false;

        $envelope = $document->createElementNS(self::SOAP_NAMESPACE, 'soap:Envelope');
        $document->appendChild($envelope);
        $body = $document->createElementNS(self::SOAP_NAMESPACE, 'soap:Body');
        $envelope->appendChild($body);
        $request = $document->createElementNS(self::WSDL_NAMESPACE, 'nfeRecepcaoEvento');
        $body->appendChild($request);
        $dadosMsg = $document->createElementNS(self::WSDL_NAMESPACE, 'nfeDadosMsg');
        $request->appendChild($dadosMsg);
        $envEvento = $document->createElementNS(self::NFE_NAMESPACE, 'envEvento');
        $envEvento->setAttribute('versao', '1.00');
        $dadosMsg->appendChild($envEvento);
        $envEvento->appendChild($document->createElementNS(self::NFE_NAMESPACE, 'idLote', now()->format('YmdHis')));

        $evento = $document->createElementNS(self::NFE_NAMESPACE, 'evento');
        $evento->setAttribute('versao', '1.00');
        $envEvento->appendChild($evento);

        $infEvento = $document->createElementNS(self::NFE_NAMESPACE, 'infEvento');
        $infEvento->setAttribute('Id', $id);
        $evento->appendChild($infEvento);
        $infEvento->appendChild($document->createElementNS(self::NFE_NAMESPACE, 'cOrgao', '91'));
        $infEvento->appendChild($document->createElementNS(self::NFE_NAMESPACE, 'tpAmb', (string) $environment));
        $infEvento->appendChild($document->createElementNS(self::NFE_NAMESPACE, 'CNPJ', $cnpj));
        $infEvento->appendChild($document->createElementNS(self::NFE_NAMESPACE, 'chNFe', $accessKey));
        $infEvento->appendChild($document->createElementNS(self::NFE_NAMESPACE, 'dhEvento', now()->format('Y-m-d\TH:i:sP')));
        $infEvento->appendChild($document->createElementNS(self::NFE_NAMESPACE, 'tpEvento', self::EVENT_TYPE_SCIENCE));
        $infEvento->appendChild($document->createElementNS(self::NFE_NAMESPACE, 'nSeqEvento', (string) $sequence));
        $infEvento->appendChild($document->createElementNS(self::NFE_NAMESPACE, 'verEvento', '1.00'));

        $detEvento = $document->createElementNS(self::NFE_NAMESPACE, 'detEvento');
        $detEvento->setAttribute('versao', '1.00');
        $infEvento->appendChild($detEvento);
        $detEvento->appendChild($document->createElementNS(self::NFE_NAMESPACE, 'descEvento', 'Ciencia da Operacao'));

        $this->appendSignature($document, $evento, $infEvento, $privateKeyPem, $certificatePem, '#' . $id);

        return $document->saveXML() ?: '';
    }

    /**
     * @return array<string,mixed>
     */
    public function parseSoapResponse(string $responseXml): array
    {
        $document = new DOMDocument();
        if (! $document->loadXML($responseXml)) {
            throw new RuntimeException('A SEFAZ retornou um XML inválido na manifestação do destinatário.');
        }

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('nfe', self::NFE_NAMESPACE);

        /** @var DOMElement|null $retEnvEvento */
        $retEnvEvento = $xpath->query('//*[local-name()="retEnvEvento"]')->item(0);
        if (! $retEnvEvento instanceof DOMElement) {
            throw new RuntimeException('A resposta da SEFAZ não contém o bloco retEnvEvento.');
        }

        /** @var DOMElement|null $infEvento */
        $infEvento = $xpath->query('.//*[local-name()="infEvento"]', $retEnvEvento)->item(0);

        return [
            'success' => in_array($this->stringValue($xpath, './/*[local-name()="cStat"]', $infEvento), ['135', '136', '573'], true),
            'batch_status_code' => $this->stringValue($xpath, './*[local-name()="cStat"]', $retEnvEvento),
            'batch_status_message' => $this->stringValue($xpath, './*[local-name()="xMotivo"]', $retEnvEvento),
            'event_status_code' => $this->stringValue($xpath, './/*[local-name()="cStat"]', $infEvento),
            'event_status_message' => $this->stringValue($xpath, './/*[local-name()="xMotivo"]', $infEvento),
            'protocol' => $this->stringValue($xpath, './/*[local-name()="nProt"]', $infEvento),
            'registered_at' => $this->stringValue($xpath, './/*[local-name()="dhRegEvento"]', $infEvento),
            'raw_xml' => $responseXml,
        ];
    }

    protected function sendSoapRequest(
        string $endpoint,
        string $requestXml,
        string $certificatePem,
        string $privateKeyPem,
    ): string {
        $certFile = tempnam(sys_get_temp_dir(), 'sefaz_event_cert_');
        $keyFile = tempnam(sys_get_temp_dir(), 'sefaz_event_key_');

        if ($certFile === false || $keyFile === false) {
            throw new RuntimeException('Não foi possível preparar arquivos temporários para a manifestação DF-e.');
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
            throw new RuntimeException('Falha técnica ao manifestar DF-e na SEFAZ: ' . $errorMessage);
        }

        if ($httpCode >= 400) {
            Log::error('SefazRecepcaoEventoService: resposta HTTP de erro na manifestacao DF-e', [
                'endpoint' => $endpoint,
                'http_code' => $httpCode,
                'response_excerpt' => is_string($response) ? mb_substr(trim($response), 0, 1000) : null,
                'request_excerpt' => mb_substr($requestXml, 0, 1000),
            ]);

            throw new RuntimeException("A SEFAZ respondeu com HTTP {$httpCode} na manifestação DF-e.");
        }

        return $response;
    }

    private function appendSignature(
        DOMDocument $document,
        DOMElement $parent,
        DOMElement $signedNode,
        string $privateKeyPem,
        string $certificatePem,
        string $referenceUri,
    ): void {
        $digestValue = base64_encode(sha1($signedNode->C14N(false, false), true));

        $signature = $document->createElementNS(self::DS_NAMESPACE, 'Signature');
        $signedInfo = $document->createElementNS(self::DS_NAMESPACE, 'SignedInfo');
        $signature->appendChild($signedInfo);

        $canonicalizationMethod = $document->createElementNS(self::DS_NAMESPACE, 'CanonicalizationMethod');
        $canonicalizationMethod->setAttribute('Algorithm', 'http://www.w3.org/TR/2001/REC-xml-c14n-20010315');
        $signedInfo->appendChild($canonicalizationMethod);

        $signatureMethod = $document->createElementNS(self::DS_NAMESPACE, 'SignatureMethod');
        $signatureMethod->setAttribute('Algorithm', 'http://www.w3.org/2000/09/xmldsig#rsa-sha1');
        $signedInfo->appendChild($signatureMethod);

        $reference = $document->createElementNS(self::DS_NAMESPACE, 'Reference');
        $reference->setAttribute('URI', $referenceUri);
        $signedInfo->appendChild($reference);

        $transforms = $document->createElementNS(self::DS_NAMESPACE, 'Transforms');
        $reference->appendChild($transforms);
        $envelopedTransform = $document->createElementNS(self::DS_NAMESPACE, 'Transform');
        $envelopedTransform->setAttribute('Algorithm', 'http://www.w3.org/2000/09/xmldsig#enveloped-signature');
        $transforms->appendChild($envelopedTransform);

        $c14nTransform = $document->createElementNS(self::DS_NAMESPACE, 'Transform');
        $c14nTransform->setAttribute('Algorithm', 'http://www.w3.org/TR/2001/REC-xml-c14n-20010315');
        $transforms->appendChild($c14nTransform);

        $digestMethod = $document->createElementNS(self::DS_NAMESPACE, 'DigestMethod');
        $digestMethod->setAttribute('Algorithm', 'http://www.w3.org/2000/09/xmldsig#sha1');
        $reference->appendChild($digestMethod);
        $reference->appendChild($document->createElementNS(self::DS_NAMESPACE, 'DigestValue', $digestValue));

        $signedInfoCanonicalized = $signedInfo->C14N(false, false);
        $privateKey = openssl_pkey_get_private($privateKeyPem);
        if ($privateKey === false) {
            throw new RuntimeException('Não foi possível abrir a chave privada do certificado A1 para assinar a manifestação.');
        }

        $signatureValue = '';
        if (! openssl_sign($signedInfoCanonicalized, $signatureValue, $privateKey, OPENSSL_ALGO_SHA1)) {
            throw new RuntimeException('Falha ao assinar digitalmente a manifestação DF-e.');
        }

        $signature->appendChild($document->createElementNS(self::DS_NAMESPACE, 'SignatureValue', base64_encode($signatureValue)));

        $keyInfo = $document->createElementNS(self::DS_NAMESPACE, 'KeyInfo');
        $x509Data = $document->createElementNS(self::DS_NAMESPACE, 'X509Data');
        $x509Certificate = preg_replace('/\-+BEGIN CERTIFICATE\-+|\-+END CERTIFICATE\-+|\s+/', '', $certificatePem) ?? '';
        $x509Data->appendChild($document->createElementNS(self::DS_NAMESPACE, 'X509Certificate', $x509Certificate));
        $keyInfo->appendChild($x509Data);
        $signature->appendChild($keyInfo);

        $parent->appendChild($signature);
    }

    private function resolveEndpoint(int $environment): string
    {
        return $environment === NfeConfigService::AMBIENTE_PRODUCAO
            ? 'https://www.nfe.fazenda.gov.br/NFeRecepcaoEvento4/NFeRecepcaoEvento4.asmx'
            : 'https://hom1.nfe.fazenda.gov.br/NFeRecepcaoEvento4/NFeRecepcaoEvento4.asmx';
    }

    private function stringValue(DOMXPath $xpath, string $expression, ?DOMElement $context = null): string
    {
        $node = $xpath->query($expression, $context)->item(0);

        return trim((string) $node?->textContent);
    }
}
