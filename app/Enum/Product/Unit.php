<?php

namespace App\Enum\Product;

enum Unit: string
{
    case UN = 'UN';
    case PC = 'PC';
    case KG = 'KG';
    case GR = 'GR';
    case MT = 'MT';
    case LT = 'LT';
    case ML = 'ML';
    case BD = 'BD';
    case TB = 'TB';
    case CX = 'CX';
    case PT = 'PT';
    case SC = 'SC';
    case FD = 'FD';
    case RL = 'RL';
    case PR = 'PR';
    case JG = 'JG';
    case DZ = 'DZ';
    case GL = 'GL';
    case CT = 'CT';
    case M2 = 'M2';
    case M3 = 'M3';
    case TON = 'TON';

    public function description(): string
    {
        return match ($this) {
            self::UN => 'Unidade',
            self::PC => 'Peça',
            self::KG => 'Quilograma',
            self::GR => 'Grama',
            self::MT => 'Metro',
            self::LT => 'Litro',
            self::ML => 'Mililitro',
            self::BD => 'Balde',
            self::TB => 'Tambor',
            self::CX => 'Caixa',
            self::PT => 'Pacote',
            self::SC => 'Saco',
            self::FD => 'Fardo',
            self::RL => 'Rolo',
            self::PR => 'Par',
            self::JG => 'Jogo',
            self::DZ => 'Dúzia',
            self::GL => 'Galão',
            self::CT => 'Cento',
            self::M2 => 'Metro Quadrado',
            self::M3 => 'Metro Cúbico',
            self::TON => 'Tonelada',
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
