<?php

namespace App\Enum\Financial;

enum DreView: string
{
    case REALIZED = 'realized';
    case PROJECTED_ONLY = 'projected_only';
    case PROJECTED_AND_REALIZED = 'projected_and_realized';
    case PROJECTED_AND_REALIZED_SEPARATED = 'projected_and_realized_separated';
    case COMPARATIVE = 'comparative';

    public function description(): string
    {
        return match ($this) {
            self::REALIZED => 'Realizado',
            self::PROJECTED_ONLY => 'Somente previsto',
            self::PROJECTED_AND_REALIZED => 'Previsto + realizado',
            self::PROJECTED_AND_REALIZED_SEPARATED => 'Previsto e realizado separados',
            self::COMPARATIVE => 'Comparativo',
        };
    }

    public static function toSelectArray(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->description()])
            ->toArray();
    }
}
