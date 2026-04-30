<?php

namespace App\Filament\Clusters\Financial\Resources\FiscalDocuments\Actions;

use App\Enum\SefazDistributionDocument\ImportStatus;
use App\Filament\Clusters\Financial\Resources\FiscalDocuments\FiscalDocumentResource;
use App\Filament\Clusters\Sales\Resources\Components\SelectPartner;
use App\Models\FiscalDocument;
use App\Models\SefazDistributionDocument;
use App\Services\Fiscal\Sefaz\SefazDistributionFiscalDocumentImportService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

class ImportSefazDfeAction
{
    public static function make(): Action
    {
        return Action::make('importSefazDfe')
            ->label('Importar DF-e')
            ->icon(Heroicon::ArrowDownTray)
            ->color('success')
            ->modalHeading('Importar DF-e para nota de entrada')
            ->modalDescription('Selecione o fornecedor e escolha um DF-e ainda não importado para gerar a nota de entrada.')
            ->modalWidth('3xl')
            ->schema([
                SelectPartner::make('partner_id', 'all')
                    ->label('Fornecedor')
                    ->live()
                    ->afterStateUpdated(function (Set $set): void {
                        $set('distribution_document_id', null);
                    }),

                Select::make('distribution_document_id')
                    ->label('DF-e disponível')
                    ->required()
                    ->native(false)
                    ->searchable()
                    ->options(fn(Get $get): array => static::documentOptions($get('partner_id')))
                    ->helperText(fn(Get $get): string => static::documentsHelperText($get('partner_id')))
                    ->disabled(fn(Get $get): bool => blank($get('partner_id'))),

                Callout::make('import_hint')
                    ->info()
                    ->description('A importação reutiliza o fluxo atual do DF-e. Se já existir nota de entrada com a mesma chave, o vínculo será reaproveitado.'),
            ])
            ->action(function (array $data): void {
                $distributionDocument = static::eligibleDocumentsQuery($data['partner_id'] ?? null)
                    ->findOrFail($data['distribution_document_id'] ?? null);

                $existingFiscalDocumentId = $distributionDocument->fiscal_document_id;
                $existingByKey = FiscalDocument::query()
                    ->where('company_id', $distributionDocument->company_id)
                    ->where('document_key', $distributionDocument->document_key)
                    ->exists();

                $fiscalDocument = app(SefazDistributionFiscalDocumentImportService::class)
                    ->import($distributionDocument, Auth::id());

                $reusedExisting = $existingFiscalDocumentId !== null || $existingByKey;

                Notification::make()
                    ->title($reusedExisting ? 'DF-e vinculado' : 'DF-e importado')
                    ->body($reusedExisting
                        ? "O DF-e foi vinculado a nota de entrada #{$fiscalDocument->id}."
                        : "O DF-e foi importado para a nota de entrada #{$fiscalDocument->id}.")
                    ->success()
                    ->send();

                redirect(FiscalDocumentResource::getUrl('edit', [
                    'record' => $fiscalDocument,
                    'tenant' => Filament::getTenant(),
                ]));
            });
    }

    private static function documentsHelperText(mixed $partnerId): string
    {
        if (blank($partnerId)) {
            return 'Selecione um fornecedor para listar os DF-es pendentes de importação.';
        }

        $count = static::eligibleDocumentsQuery($partnerId)->count();

        if ($count === 0) {
            return 'Nenhum DF-e elegível encontrado para este fornecedor.';
        }

        return $count === 1
            ? '1 DF-e elegível encontrado para este fornecedor.'
            : "{$count} DF-es elegíveis encontrados para este fornecedor.";
    }

    /**
     * @return array<int|string,string>
     */
    private static function documentOptions(mixed $partnerId): array
    {
        if (blank($partnerId)) {
            return [];
        }

        return static::eligibleDocumentsQuery($partnerId)
            ->get([
                'id',
                'document_number',
                'document_series',
                'issued_at',
                'total_amount',
                'document_key',
            ])
            ->mapWithKeys(function (SefazDistributionDocument $document): array {
                $parts = array_filter([
                    'NF ' . ($document->document_number ?: '-'),
                    $document->document_series ? 'Serie ' . $document->document_series : null,
                    $document->issued_at?->format('d/m/Y H:i'),
                    $document->total_amount !== null ? 'R$ ' . number_format((float) $document->total_amount, 2, ',', '.') : null,
                    $document->document_key,
                ]);

                return [$document->id => implode(' | ', $parts)];
            })
            ->all();
    }

    private static function eligibleDocumentsQuery(mixed $partnerId)
    {
        $tenant = Filament::getTenant();

        return SefazDistributionDocument::query()
            ->where('company_id', $tenant->id)
            ->where('partner_id', $partnerId)
            ->where('full_xml_available', true)
            ->whereNull('fiscal_document_id')
            ->whereIn('import_status', [
                ImportStatus::READY_TO_IMPORT->value,
                ImportStatus::IMPORT_ERROR->value,
            ])
            ->orderByDesc('issued_at')
            ->orderByDesc('id');
    }
}
