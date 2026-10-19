<?php

namespace App\Enum\Commitment;

enum MatchStatus: string
{
    case UNMATCHED = 'unmatched';
    case SUGGESTED = 'suggested';
    case APPROVED = 'approved';
    case IGNORED = 'ignored';

    public function description(): string
    {
        return match ($this) {
            self::UNMATCHED => 'Sem correspondência',
            self::SUGGESTED => 'OS sugerida',
            self::APPROVED => 'Vínculo aprovado',
            self::IGNORED => 'Ignorado',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::UNMATCHED => 'warning',
            self::SUGGESTED => 'info',
            self::APPROVED => 'success',
            self::IGNORED => 'gray',
        };
    }
}
