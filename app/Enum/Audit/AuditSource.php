<?php

namespace App\Enum\Audit;

enum AuditSource: string
{
    case WEB = 'web';
    case JOB = 'job';
    case COMMAND = 'command';
    case INTEGRATION = 'integration';
    case SYSTEM = 'system';
    case PUBLIC = 'public';

    public function description(): string
    {
        return match ($this) {
            self::WEB => 'Painel',
            self::JOB => 'Job',
            self::COMMAND => 'Comando',
            self::INTEGRATION => 'Integração',
            self::SYSTEM => 'Sistema',
            self::PUBLIC => 'Link público',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::WEB => 'info',
            self::JOB => 'warning',
            self::COMMAND => 'gray',
            self::INTEGRATION => 'success',
            self::SYSTEM => 'gray',
            self::PUBLIC => 'success',
        };
    }

    public static function toSelectArray(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case): array => [$case->value => $case->description()])
            ->toArray();
    }
}
