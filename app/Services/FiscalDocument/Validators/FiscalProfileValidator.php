<?php

namespace App\Services\FiscalDocument\Validators;

use App\Enum\Tax\TaxRegime;
use App\Models\FiscalProfile;
use Illuminate\Validation\ValidationException;

/**
 * Valida a existência e compatibilidade do perfil fiscal
 * com os dados do documento/itens antes da emissão.
 */
class FiscalProfileValidator
{
    /**
     * Valida que a empresa possui perfil fiscal ativo.
     *
     * @throws ValidationException
     */
    public static function validateProfileExists(int $companyId): void
    {
        $profile = FiscalProfile::where('company_id', $companyId)->first();

        if (! $profile || ! $profile->is_active) {
            throw ValidationException::withMessages([
                'fiscal_profile' => 'A empresa não possui um Perfil Fiscal configurado. Configure em Configurações > Perfil Fiscal.',
            ]);
        }
    }

    /**
     * Valida que a natureza da operação possui CFOP configurado no Perfil Fiscal.
     *
     * @throws ValidationException
     */
    public static function validateOperationNatureConfigured(int $companyId, string $operationNature): void
    {
        $profile = FiscalProfile::where('company_id', $companyId)
            ->where('is_active', true)
            ->first();

        if (! $profile) {
            return;
        }

        if ($profile->getCfopForNature($operationNature) === null) {
            throw ValidationException::withMessages([
                'operation_nature' => "Não existe CFOP configurado para a operação \"{$operationNature}\" no Perfil Fiscal. Configure em Configurações > Perfil Fiscal.",
            ]);
        }
    }

    /**
     * Valida compatibilidade CST/CSOSN dos itens com o regime tributário da empresa.
     *
     * Regras:
     * - MEI e Simples Nacional devem usar CSOSN (100-900), não CST (00-90)
     * - Lucro Presumido e Lucro Real devem usar CST (00-90), não CSOSN (100-900)
     *
     * @throws ValidationException
     */
    public static function validateItemsTaxCompatibility(int $companyId, array $items): void
    {
        $profile = FiscalProfile::where('company_id', $companyId)->first();

        if (! $profile) {
            return; // será capturado por validateProfileExists
        }

        $regime = $profile->tax_regime;
        $usaCsosn = $regime->usaCsosn();
        $errors = [];

        foreach ($items as $index => $item) {
            $icmsSt = $item['tax_data']['imposto']['icms']['situacao_tributaria'] ?? null;

            if ($icmsSt === null) {
                continue;
            }

            $stInt = (int) $icmsSt;
            $isCsosn = $stInt >= 100;

            if ($usaCsosn && ! $isCsosn) {
                $errors["items.{$index}.tax_data.imposto.icms.situacao_tributaria"] =
                    "Item #{$index}: O regime {$regime->description()} exige CSOSN (100-900), mas foi informado CST '{$icmsSt}'.";
            }

            if (! $usaCsosn && $isCsosn) {
                $errors["items.{$index}.tax_data.imposto.icms.situacao_tributaria"] =
                    "Item #{$index}: O regime {$regime->description()} exige CST ICMS (00-90), mas foi informado CSOSN '{$icmsSt}'.";
            }
        }

        if (! empty($errors)) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Valida compatibilidade do CFOP com a operação (interna vs interestadual).
     * CFOPs 5xxx = operação interna, 6xxx = operação interestadual, 7xxx = exportação.
     *
     * @throws ValidationException
     */
    public static function validateCfopCompatibility(array $items, string $issuerUf, string $recipientUf): void
    {
        $isInterestadual = mb_strtoupper($issuerUf) !== mb_strtoupper($recipientUf);
        $errors = [];

        foreach ($items as $index => $item) {
            $cfop = $item['cfop_code'] ?? null;

            if (! $cfop || strlen($cfop) < 4) {
                continue;
            }

            $firstDigit = (int) $cfop[0];

            if ($isInterestadual && $firstDigit === 5) {
                $errors["items.{$index}.cfop_code"] =
                    "Item #{$index}: CFOP '{$cfop}' é para operação interna, mas o destinatário está em outro estado ({$recipientUf}).";
            }

            if (! $isInterestadual && $firstDigit === 6) {
                $errors["items.{$index}.cfop_code"] =
                    "Item #{$index}: CFOP '{$cfop}' é para operação interestadual, mas o destinatário está no mesmo estado.";
            }
        }

        if (! empty($errors)) {
            throw ValidationException::withMessages($errors);
        }
    }
}
