<?php

namespace App\Enum\Financial;

enum DreView: string
{
    case REALIZED = 'realized';
    case PROJECTED_AND_REALIZED = 'projected_and_realized';
    case COMPARATIVE = 'comparative';

    public function description(): string
    {
        return match ($this) {
            self::REALIZED => 'Realizado',
            self::PROJECTED_AND_REALIZED => 'Previsto + realizado',
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
