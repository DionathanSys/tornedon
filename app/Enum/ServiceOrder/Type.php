<?php

namespace App\Enum\ServiceOrder;

enum Type: string
{
    case INSTALLATION = 'instalacao';
    case MAINTENANCE = 'manutencao';
    case REPAIR = 'reparo';
    case CONSULTATION = 'consultoria';
    case INSPECTION = 'inspecao';
    case CONFIGURATION = 'configuracao';
    case OTHER = 'outro';

    public function description(): string
    {
        return match ($this) {
            self::INSTALLATION => 'Instalação',
            self::MAINTENANCE => 'Manutenção',
            self::REPAIR => 'Reparo',
            self::CONSULTATION => 'Consultoria',
            self::INSPECTION => 'Inspeção',
            self::CONFIGURATION => 'Configuração',
            self::OTHER => 'Outro',
        };
    }

    public static function toSelectArray(): array
    {
        return collect(self::cases())->mapWithKeys(fn($case) => [
            $case->value => $case->description(),
        ])->toArray();
    }
}
