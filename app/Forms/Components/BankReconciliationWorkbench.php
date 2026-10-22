<?php

namespace App\Forms\Components;

use App\Models\BankStatementLine;
use App\Services\Financial\BankStatement\SearchBankReconciliationCandidates;
use Filament\Facades\Filament;
use Filament\Forms\Components\Field;
use Filament\Support\Components\Attributes\ExposedLivewireMethod;
use Illuminate\Support\Collection;
use Livewire\Attributes\Renderless;

class BankReconciliationWorkbench extends Field
{
    protected string $view = 'forms.components.bank-reconciliation-workbench';

    protected Collection $statementLines;

    public function statementLines(Collection $lines): static
    {
        $this->statementLines = $lines;

        return $this;
    }

    public function getBankSummary(): array
    {
        $first = $this->statementLines->first();

        return [
            'account' => $first?->financialAccount?->name,
            'inflow' => $first?->isInflow(),
            'total_cents' => (int) $this->statementLines->sum(fn ($line) => round((float) $line->amount * 100)),
            'valid' => $this->statementLines->isNotEmpty() && $this->statementLines->every(fn ($line) => (int) $line->company_id === (int) Filament::getTenant()->id
                && $line->financial_account_id === $first->financial_account_id
                && $line->direction() === $first->direction()
                && $line->reconciliation_status?->canResolve() === true),
            'lines' => $this->statementLines->map(fn (BankStatementLine $line) => [
                'id' => $line->id, 'description' => $line->description,
                'date' => $line->transaction_date?->format('d/m/Y'),
                'amount_cents' => (int) round((float) $line->amount * 100),
            ])->values()->all(),
        ];
    }

    #[ExposedLivewireMethod]
    #[Renderless]
    public function searchRecords(string $type, string $search = '', int $page = 1): array
    {
        return app(SearchBankReconciliationCandidates::class)->search(
            Filament::getTenant()->id, $this->statementLines, $type, $search, $page,
        );
    }
}
