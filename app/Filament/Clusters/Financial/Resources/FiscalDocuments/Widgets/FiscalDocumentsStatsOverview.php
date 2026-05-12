<?php

namespace App\Filament\Clusters\Financial\Resources\FiscalDocuments\Widgets;

use App\Filament\Clusters\Financial\Resources\FiscalDocuments\Pages\ListFiscalDocuments;
use App\Models\FiscalDocumentItem;
use Filament\Widgets\Concerns\InteractsWithPageTable;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Override;

class FiscalDocumentsStatsOverview extends StatsOverviewWidget
{
    use InteractsWithPageTable;

    protected static bool $isDiscovered = false;

    protected function getTablePage(): string
    {
        return ListFiscalDocuments::class;
    }

    protected function getStats(): array
    {
        $documentIdsQuery = $this->getPageTableQuery()
            ->clone()
            ->reorder()
            ->select('fiscal_documents.id');

        $itemsTotal = FiscalDocumentItem::query()
            ->whereIn('fiscal_document_id', $documentIdsQuery)
            ->sum('total_price');

        return [
            Stat::make('Total dos documentos', $this->formatMoney($itemsTotal / 100))
                ->description('Valor total dos documentos'),
        ];
    }

    private function formatMoney(float $amount): string
    {
        return 'R$ ' . number_format($amount, 2, ',', '.');
    }
}
