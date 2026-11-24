<?php

namespace App\Forms\Components;

use App\Models\BankStatementLine;
use App\Models\CostCenter;
use App\Models\FinancialCategory;
use App\Models\Partner;
use App\Models\ResultCenter;
use App\Services\Financial\BankStatement\BankReconciliationQualityService;
use App\Services\Financial\BankStatement\GetBankReconciliationSuggestions;
use App\Services\Financial\BankStatement\SearchBankReconciliationCandidates;
use App\Support\Financial\Money;
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
            'total_cents' => Money::sum($this->statementLines, 'amount'),
            'valid' => $this->statementLines->isNotEmpty() && $this->statementLines->every(fn ($line) => (int) $line->company_id === (int) Filament::getTenant()->id
                && $line->financial_account_id === $first->financial_account_id
                && $line->direction() === $first->direction()
                && $line->direction() !== null && ! $line->hasFinancialEffect()
                && $line->reconciliation_status?->canResolve() === true),
            'lines' => $this->statementLines->map(fn (BankStatementLine $line) => [
                'id' => $line->id, 'description' => $line->description,
                'date' => $line->transaction_date?->format('d/m/Y'),
                'amount_cents' => Money::stored($line),
            ])->values()->all(),
        ];
    }

    public function getRetainedExpenseOptions(): array
    {
        $companyId = Filament::getTenant()->id;

        return [
            'categories' => FinancialCategory::optionsForCompany($companyId, 'payable'),
            'partners' => Partner::whereHas('companies', fn ($query) => $query->where('companies.id', $companyId)->where('company_partner.is_active', true))->orderBy('name')->pluck('name', 'id')->all(),
            'cost_centers' => CostCenter::where('company_id', $companyId)->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all(),
            'result_centers' => ResultCenter::where('company_id', $companyId)->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all(),
        ];
    }

    #[ExposedLivewireMethod]
    #[Renderless]
    public function rejectSuggestions(string $evaluationToken): void
    {
        app(BankReconciliationQualityService::class)->reject(
            Filament::getTenant()->id, $this->statementLines, $evaluationToken, auth()->id(),
        );
    }

    #[ExposedLivewireMethod]
    #[Renderless]
    public function loadSuggestions(): array
    {
        return app(GetBankReconciliationSuggestions::class)->get(
            Filament::getTenant()->id, $this->statementLines,
        );
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
