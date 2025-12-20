<?php

namespace App\Enum\Tax;

enum StateTaxIndicator: string
{
    case CONTRIBUINTE_ICMS = '1';
    case CONTRIBUINTE_ICMS_ISENTO = '2';
    case NAO_CONTRIBUINTE = '9';

    public function description(): string
    {
        return match ($this) {
            self::CONTRIBUINTE_ICMS         => 'Contribuinte ICMS (informar a IE do destinatário)',
            self::CONTRIBUINTE_ICMS_ISENTO  => 'Contribuinte isento de Inscrição no cadastro de Contribuintes do ICMS',
            self::NAO_CONTRIBUINTE          => 'Não Contribuinte, que pode ou não possuir Inscrição Estadual no Cadastro de Contribuintes do ICMS',
        };
    }

    /**
     * Retorna um array compatível com Select: [value => label].
     */
    public static function toSelectArray(): array
    {
        $options = [];

        foreach (self::cases() as $item) {
            /** @var self $item */
            $options[$item->value] = $item->description();
        }

        return $options;
    }
}
