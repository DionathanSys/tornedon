<?php

namespace App\Services\FiscalDocument\XmlExport;

use App\Enum\FiscalDocument\DocumentModel;
use App\Enum\FiscalDocument\NfeStatus;
use App\Enum\FiscalDocument\OperationType;
use App\Models\FiscalDocument;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class OutputFiscalDocumentXmlExportQuery
{
    public const DATE_COLUMNS = [
        'issued_at' => 'Data de emissão',
        'created_at' => 'Criado em',
    ];

    public function query(int $companyId, Carbon $startDate, Carbon $endDate, string $dateColumn): Builder
    {
        $dateColumn = $this->normalizeDateColumn($dateColumn);

        return FiscalDocument::query()
            ->select(['id', 'company_id', 'document_type', 'document_key', 'document_number', 'document_series', 'issued_at', 'created_at'])
            ->where('company_id', $companyId)
            ->where('operation_type', OperationType::SAIDA->value)
            ->where(function (Builder $query): void {
                $query
                    ->where(function (Builder $query): void {
                        $query->where('document_type', DocumentModel::NFE->value)
                            ->where('nfe_status', NfeStatus::AUTHORIZED->value);
                    })
                    ->orWhere(function (Builder $query): void {
                        $query->where('document_type', DocumentModel::NFSE->value)
                            ->where('nfse_status', NfeStatus::AUTHORIZED->value);
                    });
            })
            ->whereNotNull('document_key')
            ->where('document_key', '!=', '')
            ->when(
                $dateColumn === 'created_at',
                fn (Builder $query): Builder => $query->whereBetween('created_at', [$startDate->copy()->startOfDay(), $endDate->copy()->endOfDay()]),
                fn (Builder $query): Builder => $query->whereBetween('issued_at', [$startDate->toDateString(), $endDate->toDateString()]),
            )
            ->orderByRaw('document_number + 0')
            ->orderBy($dateColumn);
    }

    public function normalizeDateColumn(string $dateColumn): string
    {
        return array_key_exists($dateColumn, self::DATE_COLUMNS) ? $dateColumn : 'issued_at';
    }
}
