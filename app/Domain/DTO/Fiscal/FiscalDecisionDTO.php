<?php

namespace App\Domain\DTO\Fiscal;

use Illuminate\Support\Facades\Log;

class FiscalDecisionDTO
{
    public function __construct(
        // CFOP
        public readonly ?string $cfop,

        // ICMS
        public readonly ?string $cstIcms,
        public readonly ?string $csosn,
        public readonly ?string $modBcIcms,
        public readonly ?float $aliquotaIcms,
        public readonly ?float $reducaoBaseIcms,

        // ICMS ST
        public readonly ?string $modBcSt,
        public readonly ?float $aliquotaMvaSt,
        public readonly ?float $aliquotaSt,
        public readonly ?float $reducaoBaseSt,

        // PIS
        public readonly ?string $cstPis,
        public readonly ?float $aliquotaPis,

        // COFINS
        public readonly ?string $cstCofins,
        public readonly ?float $aliquotaCofins,

        // IPI
        public readonly ?string $cstIpi,
        public readonly ?float $aliquotaIpi,
        public readonly ?string $enquadramentoIpi,

        // ISS (NFS-e)
        public readonly ?float $issAliquota = null,
        public readonly ?bool $issRetido = null,
        public readonly ?string $issExigibilidade = null,

        // Rastreabilidade
        public readonly string $source = 'regime_default', // product_tax, regime_default

        // Extensível para IBS/CBS
        public readonly ?array $metadata = null,
    ) {
    }

    /**
     * Calcula e monta o bloco `imposto` no formato esperado pela IntegraNotas.
     */
    public function toTaxData(float $baseCalculo): array
    {
        $imposto = [];

        // ICMS
        $icms = [];
        $icms['situacao_tributaria'] = $this->csosn ?? $this->cstIcms ?? '00';

        if ($this->modBcIcms !== null) {
            $icms['modalidade_base_calculo'] = $this->modBcIcms;
        }

        $bcIcms = $baseCalculo;
        if ($this->reducaoBaseIcms !== null && $this->reducaoBaseIcms > 0) {
            $bcIcms = round($baseCalculo * (1 - $this->reducaoBaseIcms / 100), 2);
        }

        $icms['valor_base_calculo'] = $bcIcms;
        $icms['aliquota'] = $this->aliquotaIcms ?? 0;
        $icms['valor'] = round($bcIcms * ($this->aliquotaIcms ?? 0) / 100, 2);

        // ICMS ST
        if ($this->aliquotaSt !== null && $this->aliquotaSt > 0) {
            $bcSt = $baseCalculo;
            if ($this->aliquotaMvaSt !== null) {
                $bcSt = round($baseCalculo * (1 + $this->aliquotaMvaSt / 100), 2);
            }
            if ($this->reducaoBaseSt !== null && $this->reducaoBaseSt > 0) {
                $bcSt = round($bcSt * (1 - $this->reducaoBaseSt / 100), 2);
            }
            if ($this->modBcSt !== null) {
                $icms['modalidade_base_calculo_st'] = $this->modBcSt;
            }
            if ($this->aliquotaMvaSt !== null) {
                $icms['aliquota_margem_valor_adicionado_st'] = $this->aliquotaMvaSt;
            }
            $icms['valor_base_calculo_st'] = $bcSt;
            $icms['aliquota_st'] = $this->aliquotaSt;
            $icms['valor_st'] = round($bcSt * $this->aliquotaSt / 100, 2);
        }

        $imposto['icms'] = $icms;

        // PIS
        $pis = [];
        $pis['situacao_tributaria'] = $this->cstPis ?? '01';
        $pis['valor_base_calculo'] = $baseCalculo;
        $pis['aliquota'] = $this->aliquotaPis ?? 0;
        $pis['valor'] = round($baseCalculo * ($this->aliquotaPis ?? 0) / 100, 2);
        $imposto['pis'] = $pis;

        // COFINS
        $cofins = [];
        $cofins['situacao_tributaria'] = $this->cstCofins ?? '01';
        $cofins['valor_base_calculo'] = $baseCalculo;
        $cofins['aliquota'] = $this->aliquotaCofins ?? 0;
        $cofins['valor'] = round($baseCalculo * ($this->aliquotaCofins ?? 0) / 100, 2);
        $imposto['cofins'] = $cofins;

        // IPI (se aplicável)
        if ($this->cstIpi !== null) {
            $ipi = [];
            $ipi['situacao_tributaria'] = $this->cstIpi;
            if ($this->aliquotaIpi !== null) {
                $ipi['valor_base_calculo'] = $baseCalculo;
                $ipi['aliquota'] = $this->aliquotaIpi;
                $ipi['valor'] = round($baseCalculo * $this->aliquotaIpi / 100, 2);
            }
            if ($this->enquadramentoIpi !== null) {
                $ipi['codigo_enquadramento'] = $this->enquadramentoIpi;
            }
            $imposto['ipi'] = $ipi;
        }

        return ['imposto' => $imposto];
    }

    /**
     * Monta dados tributários para um item NFS-e (ISS + retenções declaratórias).
     */
    public function toNfseTaxData(float $valorServicos): array
    {
        $issValor = round($valorServicos * ($this->issAliquota ?? 0) / 100, 2);

        return [
            'iss' => [
                'exigibilidade' => $this->issExigibilidade ?? '1',
                'aliquota'      => $this->issAliquota ?? 0,
                'valor'         => $issValor,
                'retido'        => $this->issRetido ?? false,
            ],
        ];
    }

    /**
     * Serializa o snapshot completo da decisão fiscal para persistência imutável.
     */
    public function toSnapshotArray(): array
    {
        return [
            'cfop'              => $this->cfop,
            'cst_icms'          => $this->cstIcms,
            'csosn'             => $this->csosn,
            'mod_bc_icms'       => $this->modBcIcms,
            'aliquota_icms'     => $this->aliquotaIcms,
            'reducao_base_icms' => $this->reducaoBaseIcms,
            'mod_bc_st'         => $this->modBcSt,
            'aliquota_mva_st'   => $this->aliquotaMvaSt,
            'aliquota_st'       => $this->aliquotaSt,
            'reducao_base_st'   => $this->reducaoBaseSt,
            'cst_pis'           => $this->cstPis,
            'aliquota_pis'      => $this->aliquotaPis,
            'cst_cofins'        => $this->cstCofins,
            'aliquota_cofins'   => $this->aliquotaCofins,
            'cst_ipi'           => $this->cstIpi,
            'aliquota_ipi'      => $this->aliquotaIpi,
            'enquadramento_ipi' => $this->enquadramentoIpi,
            'iss_rate'      => $this->issAliquota,
            'iss_withheld'        => $this->issRetido,
            'iss_exigibilidade' => $this->issExigibilidade,
            'source'            => $this->source,
            'metadata'          => $this->metadata,
        ];
    }

    /**
     * Retorna uma cópia deste DTO com o CFOP substituído.
     */
    public function withCfop(string $cfop): self
    {
        return new self(
            cfop:             $cfop,
            cstIcms:          $this->cstIcms,
            csosn:            $this->csosn,
            modBcIcms:        $this->modBcIcms,
            aliquotaIcms:     $this->aliquotaIcms,
            reducaoBaseIcms:  $this->reducaoBaseIcms,
            modBcSt:          $this->modBcSt,
            aliquotaMvaSt:    $this->aliquotaMvaSt,
            aliquotaSt:       $this->aliquotaSt,
            reducaoBaseSt:    $this->reducaoBaseSt,
            cstPis:           $this->cstPis,
            aliquotaPis:      $this->aliquotaPis,
            cstCofins:        $this->cstCofins,
            aliquotaCofins:   $this->aliquotaCofins,
            cstIpi:           $this->cstIpi,
            aliquotaIpi:      $this->aliquotaIpi,
            enquadramentoIpi: $this->enquadramentoIpi,
            issAliquota:      $this->issAliquota,
            issRetido:        $this->issRetido,
            issExigibilidade: $this->issExigibilidade,
            source:           $this->source,
            metadata:         $this->metadata,
        );
    }

    public static function fromArray(array $data): self
    {
        Log::debug('FiscalDecisionDTO::fromArray', $data);
        $result = new self(
            cfop: $data['cfop'] ?? null,
            cstIcms: $data['cst_icms'] ?? null,
            csosn: $data['csosn'] ?? null,
            modBcIcms: $data['mod_bc_icms'] ?? null,
            aliquotaIcms: isset($data['aliquota_icms']) ? (float) $data['aliquota_icms'] : null,
            reducaoBaseIcms: isset($data['reducao_base_icms']) ? (float) $data['reducao_base_icms'] : null,
            modBcSt: $data['mod_bc_st'] ?? null,
            aliquotaMvaSt: isset($data['aliquota_mva_st']) ? (float) $data['aliquota_mva_st'] : null,
            aliquotaSt: isset($data['aliquota_st']) ? (float) $data['aliquota_st'] : null,
            reducaoBaseSt: isset($data['reducao_base_st']) ? (float) $data['reducao_base_st'] : null,
            cstPis: $data['cst_pis'] ?? null,
            aliquotaPis: isset($data['aliquota_pis']) ? (float) $data['aliquota_pis'] : null,
            cstCofins: $data['cst_cofins'] ?? null,
            aliquotaCofins: isset($data['aliquota_cofins']) ? (float) $data['aliquota_cofins'] : null,
            cstIpi: $data['cst_ipi'] ?? null,
            aliquotaIpi: isset($data['aliquota_ipi']) ? (float) $data['aliquota_ipi'] : null,
            enquadramentoIpi: $data['enquadramento_ipi'] ?? null,
            issAliquota: isset($data['iss_rate']) ? (float) $data['iss_rate'] : null,
            issRetido: isset($data['iss_withheld']) ? (bool) $data['iss_withheld'] : null,
            issExigibilidade: $data['iss_exigibilidade'] ?? null,
            source: $data['source'] ?? 'regime_default',
            metadata: $data['metadata'] ?? null,
        );
        Log::debug('FiscalDecisionDTO::fromArray completed', ['source' => $result->source]);
        return $result;
    }
}
