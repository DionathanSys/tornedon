<?php

namespace App\Domain\DTO\Financial;

final readonly class BankStatementSuggestionDTO
{
    public function __construct(
        public string $originType,
        public int $originId,
        public int $score,
        public string $label,
        public string $reason,
        public array $payload = [],
    ) {}

    public function toArray(): array
    {
        return [
            'origin_type' => $this->originType,
            'origin_id' => $this->originId,
            'score' => $this->score,
            'label' => $this->label,
            'reason' => $this->reason,
            'payload' => $this->payload,
        ];
    }
}
