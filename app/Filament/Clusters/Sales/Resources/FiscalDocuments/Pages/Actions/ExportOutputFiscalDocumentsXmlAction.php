<?php

namespace App\Filament\Clusters\Sales\Resources\FiscalDocuments\Pages\Actions;

use App\Services\FiscalDocument\XmlExport\CreateOutputFiscalDocumentXmlExportAction;
use App\Services\FiscalDocument\XmlExport\OutputFiscalDocumentXmlExportQuery;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Malzariey\FilamentDaterangepickerFilter\Fields\DateRangePicker;

final class ExportOutputFiscalDocumentsXmlAction
{
    public static function make(): Action
    {
        return Action::make('exportOutputFiscalDocumentsXml')
            ->label('XMLs ZIP')
            ->icon(Heroicon::ArrowDownTray)
            ->color('gray')
            ->modalHeading('Gerar ZIP com XMLs das notas de saída')
            ->modalDescription('O processamento será feito em fila. Ao concluir, você receberá uma notificação com link de download válido por 24 horas.')
            ->modalWidth(Width::Large)
            ->schema(self::getFormSchema())
            ->action(function (array $data): void {
                $tenantId = Filament::getTenant()?->getKey();
                $userId = Auth::id();

                if (! $tenantId || ! $userId) {
                    Notification::make()
                        ->title('Não foi possível iniciar a exportação')
                        ->body('Empresa ou usuário autenticado não identificado.')
                        ->danger()
                        ->send();

                    return;
                }

                [$startDate, $endDate] = self::parseDateRange($data['date_range'] ?? null);
                if (! $startDate || ! $endDate) {
                    Notification::make()
                        ->title('Período inválido')
                        ->body('Informe um período válido para gerar os XMLs.')
                        ->danger()
                        ->send();

                    return;
                }

                $export = app(CreateOutputFiscalDocumentXmlExportAction::class)->execute(
                    companyId: (int) $tenantId,
                    userId: (int) $userId,
                    startDate: $startDate,
                    endDate: $endDate,
                    dateColumn: (string) ($data['date_column'] ?? 'issued_at'),
                );

                Notification::make()
                    ->title('Exportação de XMLs iniciada')
                    ->body(sprintf(
                        'Foram encontrados %d documento(s). Você receberá uma notificação com o link quando o ZIP estiver pronto.',
                        (int) $export->total_documents,
                    ))
                    ->success()
                    ->send();
            });
    }

    public static function getFormSchema(): array
    {
        return [
            DateRangePicker::make('date_range')
                ->label('Período')
                ->required()
                ->format('d/m/Y')
                ->firstDayOfWeek(0)
                ->alwaysShowCalendar()
                ->autoApply(),
            Select::make('date_column')
                ->label('Data base')
                ->options(OutputFiscalDocumentXmlExportQuery::DATE_COLUMNS)
                ->default('issued_at')
                ->required()
                ->native(false),
        ];
    }

    /**
     * @return array{0:Carbon|null,1:Carbon|null}
     */
    private static function parseDateRange(mixed $dateRange): array
    {
        if (! is_string($dateRange) || blank($dateRange)) {
            return [null, null];
        }

        $dates = explode(' - ', $dateRange);
        if (count($dates) !== 2) {
            return [null, null];
        }

        try {
            return [
                Carbon::createFromFormat('d/m/Y', trim($dates[0])),
                Carbon::createFromFormat('d/m/Y', trim($dates[1])),
            ];
        } catch (\Throwable) {
            return [null, null];
        }
    }
}
