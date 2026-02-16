<?php

namespace App\Enum\Product;

enum ItemType: string
{
    case MERCADORIA_PARA_REVENDA = '00';
    case MATERIA_PRIMA = '01';
    case EMBALAGEM = '02';
    case PRODUTO_EM_PROCESSO = '03';
    case PRODUTO_ACABADO = '04';
    case SUBPRODUTO = '05';
    case PRODUTO_INTERMEDIARIO = '06';
    case MATERIAL_DE_USO_E_CONSUMO = '07';
    case ATIVO_IMOBILIZADO = '08';
    case SERVICOS = '09';
    case OUTROS_INSUMOS = '10';
    case OUTROS = '99';

    public function description(): string
    {
        return match ($this) {
            self::MERCADORIA_PARA_REVENDA => '00 - Mercadoria para revenda',
            self::MATERIA_PRIMA => '01 - Matéria-prima',
            self::EMBALAGEM => '02 - Embalagem',
            self::PRODUTO_EM_PROCESSO => '03 - Produto em processo',
            self::PRODUTO_ACABADO => '04 - Produto acabado',
            self::SUBPRODUTO => '05 - Subproduto',
            self::PRODUTO_INTERMEDIARIO => '06 - Produto intermediário',
            self::MATERIAL_DE_USO_E_CONSUMO => '07 - Material de uso e consumo',
            self::ATIVO_IMOBILIZADO => '08 - Ativo imobilizado',
            self::SERVICOS => '09 - Serviços',
            self::OUTROS_INSUMOS => '10 - Outros insumos',
            self::OUTROS => '99 - Outros',
        };
    }

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
