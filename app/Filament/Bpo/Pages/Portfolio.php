<?php

namespace App\Filament\Bpo\Pages;

use App\Services\Bpo\BpoAccessService;
use App\Services\Bpo\BpoPortfolioService;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Support\Str;

class Portfolio extends Page
{
    protected static string $layout = 'components.bpo.layout';

    protected string $view = 'filament.bpo.pages.portfolio';

    protected static ?string $title = 'Minha carteira';

    public string $search = '';

    public string $filter = 'all';

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user && app(BpoAccessService::class)->canAccessPanel($user);
    }

    public function portfolio(): array
    {
        abort_unless(static::canAccess(), 403);
        $rows = app(BpoPortfolioService::class)->rows(Filament::auth()->user());
        $search = Str::lower(Str::ascii(trim($this->search)));
        $filtered = $rows->filter(function (array $row) use ($search): bool {
            $documentSearch = preg_replace('/[.\-\/\s]/u', '', $search);
            $document = preg_replace('/[.\-\/\s]/u', '', Str::lower((string) $row['company']->document_number));
            $match = str_contains(Str::lower(Str::ascii($row['company']->name)), $search)
                || ($documentSearch !== '' && str_contains($document, $documentSearch));
            $pending = ($row['payable_overdue'] ?? 0) + ($row['receivable_overdue'] ?? 0) + ($row['reconciliation_pending'] ?? 0) + ($row['fiscal_pending'] ?? 0);

            return $match && match ($this->filter) {
                'attention' => $pending > 0,
                'responsible' => $row['is_responsible'],
                default => true,
            };
        });

        return ['rows' => $filtered, 'total' => $rows->count(),
            'attention' => $rows->filter(fn ($row) => ($row['payable_overdue'] ?? 0) + ($row['receivable_overdue'] ?? 0) + ($row['reconciliation_pending'] ?? 0) + ($row['fiscal_pending'] ?? 0) > 0)->count(),
            'reconciliation' => $rows->contains(fn ($row) => $row['reconciliation_pending'] !== null) ? $rows->sum('reconciliation_pending') : null,
            'fiscal' => $rows->contains(fn ($row) => $row['fiscal_pending'] !== null) ? $rows->sum('fiscal_pending') : null];
    }
}
