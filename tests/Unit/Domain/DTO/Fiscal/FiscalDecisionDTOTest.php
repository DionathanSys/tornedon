<?php

namespace Tests\Unit\Domain\DTO\Fiscal;

use App\Domain\DTO\Fiscal\FiscalDecisionDTO;
use PHPUnit\Framework\TestCase;

class FiscalDecisionDTOTest extends TestCase
{
    public function test_to_tax_data_includes_ibs_cbs_when_configured(): void
    {
        $decision = $this->makeDecision(
            cstIbsCbs: '000',
            classificacaoTributariaIbsCbs: '000001',
            indicadorDoacaoIbsCbs: '0',
            aliquotaIbsEstadual: 0.05,
            aliquotaIbsMunicipal: 0.05,
            aliquotaCbs: 0.90,
        );

        $taxData = $decision->toTaxData(100.00);

        $this->assertSame([
            'situacao_tributaria' => '000',
            'classificacao_tributaria' => '000001',
            'grupo_ibs_cbs' => [
                'valor_base_calculo' => '100.00',
                'valor_total_ibs' => '0.10',
                'ibs_estadual' => [
                    'aliquota' => '0.0500',
                    'valor' => '0.05',
                ],
                'ibs_municipal' => [
                    'aliquota' => '0.0500',
                    'valor' => '0.05',
                ],
                'cbs' => [
                    'aliquota' => '0.9000',
                    'valor' => '0.90',
                ],
            ],
            'indicador_doacao' => '0',
        ], $taxData['imposto']['ibs_cbs']);
    }

    public function test_to_tax_data_does_not_include_ibs_cbs_without_required_configuration(): void
    {
        $taxData = $this->makeDecision(
            cstIbsCbs: '000',
            classificacaoTributariaIbsCbs: null,
            aliquotaIbsEstadual: 0.05,
            aliquotaIbsMunicipal: 0.05,
            aliquotaCbs: 0.90,
        )->toTaxData(100.00);

        $this->assertArrayNotHasKey('ibs_cbs', $taxData['imposto']);
    }

    private function makeDecision(
        ?string $cstIbsCbs = null,
        ?string $classificacaoTributariaIbsCbs = null,
        ?string $indicadorDoacaoIbsCbs = null,
        ?float $aliquotaIbsEstadual = null,
        ?float $aliquotaIbsMunicipal = null,
        ?float $aliquotaCbs = null,
    ): FiscalDecisionDTO {
        return new FiscalDecisionDTO(
            cfop: '5102',
            cstIcms: '00',
            csosn: null,
            modBcIcms: null,
            aliquotaIcms: 18.0,
            reducaoBaseIcms: null,
            modBcSt: null,
            aliquotaMvaSt: null,
            aliquotaSt: null,
            reducaoBaseSt: null,
            cstPis: '01',
            aliquotaPis: 1.65,
            cstCofins: '01',
            aliquotaCofins: 7.6,
            cstIpi: null,
            aliquotaIpi: null,
            enquadramentoIpi: null,
            cstIbsCbs: $cstIbsCbs,
            classificacaoTributariaIbsCbs: $classificacaoTributariaIbsCbs,
            indicadorDoacaoIbsCbs: $indicadorDoacaoIbsCbs,
            aliquotaIbsEstadual: $aliquotaIbsEstadual,
            aliquotaIbsMunicipal: $aliquotaIbsMunicipal,
            aliquotaCbs: $aliquotaCbs,
        );
    }
}
