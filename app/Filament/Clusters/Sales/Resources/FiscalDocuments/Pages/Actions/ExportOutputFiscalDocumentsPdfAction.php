<?php

namespace App\Filament\Clusters\Sales\Resources\FiscalDocuments\Pages\Actions;

use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ExportOutputFiscalDocumentsPdfAction
{
    public static function make(): Action
    {
        return Action::make('exportOutputFiscalDocumentsPdf')
            ->label('Relatório PDF')
            ->icon(Heroicon::DocumentText)
            ->color('gray')
            ->modalHeading('Gerar relatório de notas de saída em PDF')
            ->modalWidth(Width::Large)
            ->schema(ExportOutputFiscalDocumentsAction::getFormSchema())
            ->action(function (array $data): StreamedResponse {
                $records = ExportOutputFiscalDocumentsAction::getRecords($data);

                $pdfBinary = Pdf::loadView('pdf.fiscal-document-output-report', [
                    'report' => [
                        'title' => 'Relatório de Notas de Saída',
                        'companyName' => Filament::getTenant()?->name ?? config('app.name'),
                        'generatedAt' => now()->format('d/m/Y H:i'),
                        'generatedBy' => auth()->user()?->name ?? 'Sistema',
                        'period' => ExportOutputFiscalDocumentsAction::buildPeriodDescription($data),
                        'columns' => ExportOutputFiscalDocumentsAction::buildColumns(),
                        'rows' => ExportOutputFiscalDocumentsAction::buildRows($records),
                        'summary' => ExportOutputFiscalDocumentsAction::buildSummary($records),
                    ],
                ])->setPaper('a4', 'landscape')->output();

                $fileName = 'notas-saida-'.now()->format('Y-m-d_H-i-s').'.pdf';

                return response()->streamDownload(function () use ($pdfBinary): void {
                    echo $pdfBinary;
                }, $fileName, ['Content-Type' => 'application/pdf']);
            });
    }
}
