<?php

namespace App\Enum\User;

enum ManagementRole: string
{
    case SUPER_ADMIN = 'super_admin';
    case MANAGEMENT_ADMIN = 'management_admin';

    public function description(): string
    {
        return match ($this) {
            self::SUPER_ADMIN => 'Superadministrador',
            self::MANAGEMENT_ADMIN => 'Administrador de gestão',
        };
    }

    public static function toSelectArray(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case): array => [$case->value => $case->description()])
            ->all();
    }
}
