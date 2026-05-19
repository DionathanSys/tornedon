<?php

namespace App\Filament\Support\Actions;

use App\Models\FiscalDocument;
use App\Notification\NotifyService as notify;
use App\Services\FiscalDocument\NfeDocumentService;
use App\Services\FiscalDocument\NfseDocumentService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class FiscalDocumentRecordActions
{
    /**
     * @return array<int, Action|EditAction>
     */
    public static function make(bool $includeEditAction = false): array
    {
        $actions = [
            Action::make('emitir')
                ->label('Emitir NF-e')
                ->icon(Heroicon::PaperAirplane)
                ->color('primary')
                ->requiresConfirmation()
                ->modalHeading('Emitir Nota Fiscal Eletrônica')
                ->modalDescription('O envio é assíncrono. Após confirmação, a NF-e será processada em segundo plano.')
                ->visible(fn (FiscalDocument $record) => $record->isNfe() && ! $record->isNfeQueued() && (! $record->isNfeSent() || $record->isNfeRejected()))
                ->action(function (FiscalDocument $record): void {
                    $service = app(NfeDocumentService::class);
                    $service->emitir($record, Auth::id());

                    if ($service->isSuccess()) {
                        notify::success($service->getMessage());
                    } else {
                        notify::error($service->getMessage());
                    }
                }),

            Action::make('consultar')
                ->label('Consultar SEFAZ')
                ->icon(Heroicon::MagnifyingGlass)
                ->color('warning')
                ->visible(fn (FiscalDocument $record) => $record->isNfe() && $record->isNfeInProcessing())
                ->action(function (FiscalDocument $record): void {
                    $service = app(NfeDocumentService::class);
                    $service->consultar($record, Auth::id());

                    if ($service->isSuccess()) {
                        notify::success($service->getMessage());
                    } else {
                        notify::error($service->getMessage());
                    }
                }),

            Action::make('preview')
                ->label('Preview')
                ->icon(Heroicon::Eye)
                ->color('gray')
                ->visible(fn (FiscalDocument $record) => $record->isNfe())
                ->modalHeading('Preview da NF-e')
                ->modalContent(function (FiscalDocument $record): \Illuminate\Contracts\Support\Htmlable {
                    $service = app(NfeDocumentService::class);
                    $data = $service->preview($record, Auth::id());

                    if (! $data || ! $data['pdf']) {
                        return new HtmlString(
                            '<p class="text-red-500">'.($service->getMessage() ?: 'Não foi possível gerar o preview.').'</p>'
                        );
                    }

                    return new HtmlString(
                        '<iframe src="data:application/pdf;base64,'.$data['pdf'].'" width="100%" height="600px" style="border:none;"></iframe>'
                    );
                })
                ->action(function (FiscalDocument $record): void {
                    $service = app(NfeDocumentService::class);
                    $service->emitir($record, Auth::id());

                    if ($service->isSuccess()) {
                        notify::success('NF-e enfileirada para emissão. A nota será enviada automaticamente quando chegar sua vez na fila da empresa/série.');

                        return;
                    }

                    notify::error($service->getMessage());
                })
                ->modalSubmitActionLabel('Emitir')
                ->modalWidth('6xl'),

            Action::make('danfe')
                ->label('Download DANFE')
                ->icon(Heroicon::ArrowDownTray)
                ->color('success')
                ->visible(fn (FiscalDocument $record) => $record->isNfe() && $record->isNfeAuthorized())
                ->action(function (FiscalDocument $record): StreamedResponse {
                    $service = app(NfeDocumentService::class);
                    $pdf = $service->danfe($record, Auth::id());

                    if (! $pdf) {
                        notify::error($service->getMessage());

                        return response()->streamDownload(fn () => null, 'danfe.pdf');
                    }

                    $filename = 'DANFE-'.($record->document_number ?? $record->id).'.pdf';

                    return response()->streamDownload(function () use ($pdf) {
                        echo base64_decode($pdf);
                    }, $filename, ['Content-Type' => 'application/pdf']);
                }),

            Action::make('cancelar_nfe')
                ->label('Cancelar NF-e')
                ->icon(Heroicon::XCircle)
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Cancelar NF-e')
                ->modalDescription('Esta ação não pode ser desfeita. A NF-e será cancelada na SEFAZ.')
                ->visible(fn (FiscalDocument $record) => $record->isNfe() && $record->isNfeAuthorized())
                ->schema([
                    Textarea::make('justificativa')
                        ->label('Justificativa do Cancelamento')
                        ->required()
                        ->minLength(15)
                        ->maxLength(255)
                        ->rows(4),
                ])
                ->action(function (FiscalDocument $record, array $data): void {
                    $service = app(NfeDocumentService::class);
                    $service->cancelar(
                        $record,
                        $data['justificativa'],
                        Auth::id()
                    );

                    if ($service->isSuccess()) {
                        notify::success($service->getMessage());
                    } else {
                        notify::error($service->getMessage());
                    }
                }),

            Action::make('emitir_nfse')
                ->label('Emitir NFS-e')
                ->icon(Heroicon::PaperAirplane)
                ->color('primary')
                ->requiresConfirmation()
                ->modalHeading('Emitir Nota Fiscal de Serviço')
                ->modalDescription('O envio é assíncrono. Após confirmação, a NFS-e será processada em segundo plano.')
                ->visible(fn (FiscalDocument $record) => $record->isNfse() && ! $record->isNfseQueued() && (! $record->isNfseSent() || $record->isNfseRejected()))
                ->action(function (FiscalDocument $record): void {
                    $service = app(NfseDocumentService::class);
                    $service->emitir($record, Auth::id());

                    if ($service->isSuccess()) {
                        notify::success('NFS-e enfileirada para emissão. A nota será enviada automaticamente quando chegar sua vez na fila da empresa/série.');
                    } else {
                        notify::error($service->getMessage());
                    }
                }),

            Action::make('consultar_nfse')
                ->label('Consultar NFS-e')
                ->icon(Heroicon::MagnifyingGlass)
                ->color('warning')
                ->visible(fn (FiscalDocument $record) => $record->isNfse())
                ->action(function (FiscalDocument $record): void {
                    $service = app(NfseDocumentService::class);
                    $service->consultar($record, Auth::id());

                    if ($service->isSuccess()) {
                        notify::success($service->getMessage());
                    } else {
                        notify::error($service->getMessage());
                    }
                }),

            Action::make('preview_nfse')
                ->label('Preview')
                ->icon(Heroicon::Eye)
                ->color('gray')
                ->visible(fn (FiscalDocument $record) => $record->isNfse())
                ->modalHeading('Preview da NFS-e')
                ->modalContent(function (FiscalDocument $record): \Illuminate\Contracts\Support\Htmlable {
                    $service = app(NfseDocumentService::class);
                    $data = $service->preview($record, Auth::id());

                    if (! $data || ! $data['pdf']) {
                        return new HtmlString(
                            '<p class="text-red-500">'.($service->getMessage() ?: 'Não foi possível gerar o preview.').'</p>'
                        );
                    }

                    return new HtmlString(
                        '<iframe src="data:application/pdf;base64,'.$data['pdf'].'" width="100%" height="600px" style="border:none;"></iframe>'
                    );
                })
                ->modalWidth('6xl'),

            Action::make('pdf_nfse')
                ->label('Download PDF')
                ->icon(Heroicon::ArrowDownTray)
                ->color('success')
                ->visible(fn (FiscalDocument $record) => $record->isNfse() && $record->isNfseAuthorized())
                ->action(function (FiscalDocument $record): StreamedResponse {
                    $service = app(NfseDocumentService::class);
                    $pdf = $service->pdf($record, Auth::id());

                    if (! $pdf) {
                        notify::error($service->getMessage());

                        return response()->streamDownload(fn () => null, 'nfse.pdf');
                    }

                    $filename = 'NFSE-'.($record->document_number ?? $record->id).'.pdf';

                    return response()->streamDownload(function () use ($pdf) {
                        echo base64_decode($pdf);
                    }, $filename, ['Content-Type' => 'application/pdf']);
                }),

            Action::make('cancelar_nfse')
                ->label('Cancelar NFS-e')
                ->icon(Heroicon::XCircle)
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Cancelar NFS-e')
                ->modalDescription('Esta ação não pode ser desfeita. A NFS-e será cancelada na prefeitura.')
                ->visible(fn (FiscalDocument $record) => $record->isNfse() && $record->isNfseAuthorized())
                ->schema([
                    Select::make('codigo_cancelamento')
                        ->label('Código do Cancelamento')
                        ->options([
                            '1' => '1 - Erro de Emissão',
                            '2' => '2 - Serviço não concluído',
                            '9' => '9 - Outros',
                        ])
                        ->required()
                        ->native(false),
                    Textarea::make('motivo_cancelamento')
                        ->label('Motivo do Cancelamento')
                        ->required()
                        ->maxLength(80)
                        ->rows(4),
                ])
                ->action(function (FiscalDocument $record, array $data): void {
                    $service = app(NfseDocumentService::class);
                    $service->cancelar(
                        $record,
                        $data['codigo_cancelamento'],
                        $data['motivo_cancelamento'],
                        Auth::id()
                    );

                    if ($service->isSuccess()) {
                        notify::success($service->getMessage());
                    } else {
                        notify::error($service->getMessage());
                    }
                }),
        ];

        if ($includeEditAction) {
            $actions[] = EditAction::make();
        }

        return $actions;
    }
}
