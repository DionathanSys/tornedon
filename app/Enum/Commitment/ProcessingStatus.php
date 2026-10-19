<?php

namespace App\Enum\Commitment;

enum ProcessingStatus: string
{
    case QUEUED = 'queued';
    case PROCESSING = 'processing';
    case PROCESSED = 'processed';
    case NEEDS_REVIEW = 'needs_review';
    case FAILED = 'failed';

    public function description(): string
    {
        return match ($this) {
            self::QUEUED => 'Na fila',
            self::PROCESSING => 'Processando',
            self::PROCESSED => 'Processado',
            self::NEEDS_REVIEW => 'Revisão necessária',
            self::FAILED => 'Falhou',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::QUEUED => 'gray',
            self::PROCESSING => 'info',
            self::PROCESSED => 'success',
            self::NEEDS_REVIEW => 'warning',
            self::FAILED => 'danger',
        };
    }
}
