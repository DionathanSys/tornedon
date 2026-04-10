<?php

namespace App\Domain\DTO\Financial;

final readonly class OfxStatementHeaderDTO
{
    public function __construct(
        public ?string $bankId,
        public ?string $branchId,
        public ?string $accountId,
        public ?string $accountType,
        public ?string $currency,
        public ?string $statementStart,
        public ?string $statementEnd,
        public ?float $ledgerBalance,
        public ?string $institutionName = null,
    ) {}

    public function with(
        ?string $bankId = null,
        ?string $branchId = null,
        ?string $accountId = null,
        ?string $accountType = null,
        ?string $currency = null,
        ?string $statementStart = null,
        ?string $statementEnd = null,
        ?float $ledgerBalance = null,
        ?string $institutionName = null,
    ): self {
        return new self(
            $bankId ?? $this->bankId,
            $branchId ?? $this->branchId,
            $accountId ?? $this->accountId,
            $accountType ?? $this->accountType,
            $currency ?? $this->currency,
            $statementStart ?? $this->statementStart,
            $statementEnd ?? $this->statementEnd,
            $ledgerBalance ?? $this->ledgerBalance,
            $institutionName ?? $this->institutionName,
        );
    }

    public function reference(): string
    {
        return implode('|', [
            $this->bankId ?? '-',
            $this->branchId ?? '-',
            $this->accountId ?? '-',
            $this->statementStart ?? '-',
            $this->statementEnd ?? '-',
        ]);
    }

    public function toArray(): array
    {
        return [
            'bank_id' => $this->bankId,
            'branch_id' => $this->branchId,
            'account_id' => $this->accountId,
            'account_type' => $this->accountType,
            'currency' => $this->currency,
            'statement_start' => $this->statementStart,
            'statement_end' => $this->statementEnd,
            'ledger_balance' => $this->ledgerBalance,
            'institution_name' => $this->institutionName,
        ];
    }
}
