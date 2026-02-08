<?php

namespace App\Enum\Product;

enum Origin: string
{
    case NACIONAL = '0';
    case ESTRANGEIRA_IMPORTACAO_DIRETA = '1';
    case ESTRANGEIRA_MERCADO_INTERNO = '2';
    case NACIONAL_CONTEUDO_ESTRANGEIRO_ACIMA_40 = '3';
    case NACIONAL_PROCESSOS_PRODUTIVOS_BASICOS = '4';
    case NACIONAL_CONTEUDO_ESTRANGEIRO_ABAIXO_40 = '5';
    case ESTRANGEIRA_IMPORTACAO_SEM_SIMILAR = '6';
    case ESTRANGEIRA_MERCADO_INTERNO_SEM_SIMILAR = '7';
    case NACIONAL_IMPORTACAO_ACIMA_70 = '8';
    
    public function description(): string
    {
        return match ($this) {
            self::NACIONAL => '0 - Nacional',
            self::ESTRANGEIRA_IMPORTACAO_DIRETA => '1 - Estrangeira - Importação direta',
            self::ESTRANGEIRA_MERCADO_INTERNO => '2 - Estrangeira - Adquirida no mercado interno',
            self::NACIONAL_CONTEUDO_ESTRANGEIRO_ACIMA_40 => '3 - Nacional com mais de 40% de conteúdo estrangeiro',
            self::NACIONAL_PROCESSOS_PRODUTIVOS_BASICOS => '4 - Nacional produzida através de processos produtivos básicos',
            self::NACIONAL_CONTEUDO_ESTRANGEIRO_ABAIXO_40 => '5 - Nacional com menos de 40% de conteúdo estrangeiro',
            self::ESTRANGEIRA_IMPORTACAO_SEM_SIMILAR => '6 - Estrangeira - Importação direta sem similar nacional',
            self::ESTRANGEIRA_MERCADO_INTERNO_SEM_SIMILAR => '7 - Estrangeira - Adquirida no mercado interno sem similar nacional',
            self::NACIONAL_IMPORTACAO_ACIMA_70 => '8 - Nacional - Mercadoria ou bem com Conteúdo de Importação superior a 70%',
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
