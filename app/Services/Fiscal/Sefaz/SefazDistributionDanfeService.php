<?php

namespace App\Services\Fiscal\Sefaz;

use App\Models\SefazDistributionDocument;
use DOMDocument;
use DOMXPath;
use NFePHP\DA\NFe\Danfe;

class SefazDistributionDanfeService
{
    public function __construct(private readonly SefazDfeStorageService $storageService) {}

    public function render(SefazDistributionDocument $distributionDocument): string
    {
        if (! $distributionDocument->full_xml_available) {
            throw new \RuntimeException('O DANFE só pode ser gerado após o recebimento do XML completo.');
        }

        $xml = $this->storageService->read($distributionDocument->full_xml_path);

        if (! is_string($xml) || trim($xml) === '') {
            throw new \RuntimeException('O XML completo do DF-e não foi encontrado no storage.');
        }

        $dom = new DOMDocument;

        if (! @$dom->loadXML($xml) || (new DOMXPath($dom))->query('//*[local-name()="infNFe"]')->length === 0) {
            throw new \RuntimeException('O XML completo armazenado não possui uma NF-e válida para gerar o DANFE.');
        }

        try {
            return (new Danfe($xml))->render();
        } catch (\Throwable $exception) {
            throw new \RuntimeException('Não foi possível gerar o DANFE a partir do XML armazenado.', previous: $exception);
        }
    }
}
