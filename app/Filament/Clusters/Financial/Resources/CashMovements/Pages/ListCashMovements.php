<?php

namespace App\Filament\Clusters\Financial\Resources\CashMovements\Pages;

use App\Filament\Clusters\Financial\Resources\BankStatementImports\Actions\ImportOfxAction;
use App\Filament\Clusters\Financial\Resources\BankStatementImports\BankStatementImportResource;
use App\Filament\Clusters\Financial\Resources\CashMovements\CashMovementResource;
use App\Filament\Clusters\Financial\Resources\CashMovements\Widgets\CashMovementsReconciliationStats;
use App\Filament\Clusters\Financial\Resources\CashMovements\Widgets\CashMovementsStatsOverview;
use App\Models\AccountPayableInstallmentPayment;
use App\Models\AccountReceivableInstallmentPayment;
use App\Models\CashMovement;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\Concerns\ExposesTableToWidgets;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Collection;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListCashMovements extends ListRecords
{
    use ExposesTableToWidgets;

    protected static string $resource = CashMovementResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            CashMovementsStatsOverview::class,
            // CashMovementsReconciliationStats::class,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            ImportOfxAction::make(),
            Action::make('export_xls')
                ->label('Exportar XLSX')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->action(fn (): StreamedResponse => $this->exportXls()),
            Action::make('export_pdf')
                ->label('Exportar PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->action(fn (): StreamedResponse => $this->exportPdf()),
            Action::make('view_ofx_imports')
                ->label('Importações OFX')
                ->icon('heroicon-o-document-duplicate')
                ->url(BankStatementImportResource::getUrl('index', [
                    'tenant' => Filament::getTenant(),
                ])),
        ];
    }

    public function exportXls(): StreamedResponse
    {
        $report = $this->cashMovementExportReport();
        $path = tempnam(sys_get_temp_dir(), 'cash-movements-');
        $writer = new Writer;

        $writer->openToFile($path);
        $writer->addRow(Row::fromValues([
            'Data',
            'Conta',
            'nº Doc',
            'Descrição',
            'Valor',
            'Classificação',
            'Parceiro',
        ]));

        foreach ($report['rows'] as $row) {
            $writer->addRow(Row::fromValues([
                $row['date'],
                $row['account'],
                $row['document_number'],
                $row['description'],
                $row['amount'],
                $row['classification'],
                $row['partner'],
            ]));
        }

        $writer->close();

        return response()->streamDownload(function () use ($path): void {
            readfile($path);
            @unlink($path);
        }, $this->exportFileName('xlsx'), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function exportPdf(): StreamedResponse
    {
        $pdfBinary = Pdf::loadView('pdf.cash-movements-report', [
            'report' => $this->cashMovementExportReport(),
        ])->setPaper('a4', 'landscape')->output();

        return response()->streamDownload(function () use ($pdfBinary): void {
            echo $pdfBinary;
        }, $this->exportFileName('pdf'), [
            'Content-Type' => 'application/pdf',
        ]);
    }

    private function cashMovementExportReport(): array
    {
        $records = $this->getTableQueryForExport()
            ->with([
                'financialAccount',
                'financialCategory',
                'counterpartyPartner',
                'company',
            ])
            ->get();

        $documentNumbers = $this->resolveDocumentNumbers($records);

        $rows = $records
            ->map(fn (CashMovement $record): array => [
                'date' => $record->transaction_date?->format('d/m/Y') ?? '',
                'account' => $record->financialAccount?->display_name ?? $record->financialAccount?->name ?? '',
                'document_number' => $documentNumbers[$this->documentNumberKey($record)] ?? '',
                'description' => $record->description,
                'amount' => (float) $record->amount,
                'classification' => $record->financialCategory?->full_name ?? $record->financialCategory?->name ?? '',
                'partner' => $record->counterparty_label,
            ])
            ->all();

        return [
            'title' => 'Movimentos Financeiros',
            'companyName' => Filament::getTenant()?->name ?? config('app.name'),
            'generatedAt' => now()->format('d/m/Y H:i'),
            'generatedBy' => auth()->user()?->name ?? 'Sistema',
            'rows' => $rows,
            'total' => array_sum(array_column($rows, 'amount')),
        ];
    }

    /**
     * @param  Collection<int, CashMovement>  $records
     * @return array<string, string>
     */
    private function resolveDocumentNumbers(Collection $records): array
    {
        $payablePaymentIds = $records
            ->where('origin_type', AccountPayableInstallmentPayment::class)
            ->pluck('origin_id')
            ->filter()
            ->unique()
            ->values();

        $receivablePaymentIds = $records
            ->where('origin_type', AccountReceivableInstallmentPayment::class)
            ->pluck('origin_id')
            ->filter()
            ->unique()
            ->values();

        $documentNumbers = [];

        AccountPayableInstallmentPayment::query()
            ->with('installment.accountPayable')
            ->whereIn('id', $payablePaymentIds)
            ->get()
            ->each(function (AccountPayableInstallmentPayment $payment) use (&$documentNumbers): void {
                $documentNumbers[AccountPayableInstallmentPayment::class.':'.$payment->id] = (string) ($payment->installment?->accountPayable?->document_number ?? '');
            });

        AccountReceivableInstallmentPayment::query()
            ->with('installment.accountReceivable')
            ->whereIn('id', $receivablePaymentIds)
            ->get()
            ->each(function (AccountReceivableInstallmentPayment $payment) use (&$documentNumbers): void {
                $documentNumbers[AccountReceivableInstallmentPayment::class.':'.$payment->id] = (string) ($payment->installment?->accountReceivable?->document_number ?? '');
            });

        return $documentNumbers;
    }

    private function documentNumberKey(CashMovement $record): string
    {
        return $record->origin_type.':'.$record->origin_id;
    }

    private function exportFileName(string $extension): string
    {
        return 'movimentos-financeiros-'.now()->format('Y-m-d_H-i-s').'.'.$extension;
    }
}
