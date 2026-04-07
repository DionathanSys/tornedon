<?php

namespace App\Filament\Clusters\Sales\Resources\FiscalDocuments\Pages;

use App\Filament\Clusters\Sales\Resources\FiscalDocuments\FiscalDocumentResource;
use App\Models\FiscalDocument;
use App\Services\FiscalDocument\FiscalDocumentService;
use App\Services\FiscalDocument\NfeDocumentService;
use App\Services\FiscalDocument\NfseDocumentService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EditFiscalDocument extends EditRecord
{
    protected static string $resource = FiscalDocumentResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $purchaseInfo = $this->parseAdditionalPurchaseInformation(
            $data['additional_purchase_information'] ?? null
        );

        $data['additional_purchase_information_nota_empenho'] = $purchaseInfo['nota_empenho'] ?? null;
        $data['additional_purchase_information_pedido'] = $purchaseInfo['pedido'] ?? null;
        $data['additional_purchase_information_contrato'] = $purchaseInfo['contrato'] ?? null;

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['additional_purchase_information'] = $this->buildAdditionalPurchaseInformation($data);

        unset(
            $data['additional_purchase_information_nota_empenho'],
            $data['additional_purchase_information_pedido'],
            $data['additional_purchase_information_contrato'],
        );

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                Action::make('emitir')
                    ->label('Emitir NF-e')
                    ->icon(Heroicon::PaperAirplane)
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalHeading('Emitir Nota Fiscal Eletrônica')
                    ->modalDescription('O envio é assíncrono. Após confirmação, a NF-e será processada em segundo plano.')
                    ->visible(fn(FiscalDocument $record) => ! $record->isNfse() && (! $record->nfeSent() || $record->isRejected()))
                    ->action(function (FiscalDocument $record): void {
                        $service = app(NfeDocumentService::class);
                        $service->emitir($record, Auth::id());

                        if ($service->isSuccess()) {
                            Notification::make()->title($service->getMessage())->success()->send();
                            return;
                        }

                        Notification::make()->title($service->getMessage())->danger()->send();
                    })
                    ->successRedirectUrl(fn(FiscalDocument $record) => FiscalDocumentResource::getUrl('edit', ['record' => $record])),

                Action::make('consultar')
                    ->label('Consultar SEFAZ')
                    ->icon(Heroicon::MagnifyingGlass)
                    ->color('warning')
                    ->visible(fn(FiscalDocument $record) => ! $record->isNfse() && $record->isInProcessing())
                    ->action(function (FiscalDocument $record): void {
                        $service = app(NfeDocumentService::class);
                        $service->consultar($record, Auth::id());

                        if ($service->isSuccess()) {
                            Notification::make()->title($service->getMessage())->success()->send();
                            return;
                        }

                        Notification::make()->title($service->getMessage())->danger()->send();
                    }),

                Action::make('preview')
                    ->label('Preview')
                    ->icon(Heroicon::Eye)
                    ->color('gray')
                    ->visible(fn(FiscalDocument $record) => ! $record->isNfse())
                    ->modalHeading('Preview da NF-e')
                    ->modalContent(function (FiscalDocument $record): \Illuminate\Contracts\Support\Htmlable {
                        $service = app(NfeDocumentService::class);
                        $data    = $service->preview($record, Auth::id());

                        if (! $data || ! $data['pdf']) {
                            return new HtmlString(
                                '<p class="text-red-500">' . ($service->getMessage() ?: 'Não foi possível gerar o preview.') . '</p>'
                            );
                        }

                        return new HtmlString(
                            '<iframe src="data:application/pdf;base64,' . $data['pdf'] . '" width="100%" height="600px" style="border:none;"></iframe>'
                        );
                    })
                    ->modalWidth('6xl')
                    ->after(fn() => $this->refreshFormData(['errors_messages'])),

                Action::make('danfe')
                    ->label('Download DANFE')
                    ->icon(Heroicon::ArrowDownTray)
                    ->color('success')
                    ->visible(fn(FiscalDocument $record) => ! $record->isNfse() && $record->isAuthorized())
                    ->action(function (FiscalDocument $record): StreamedResponse {
                        $service = app(NfeDocumentService::class);
                        $pdf     = $service->danfe($record, Auth::id());

                        if (! $pdf) {
                            Notification::make()->title($service->getMessage())->danger()->send();
                            return response()->streamDownload(fn() => null, 'danfe.pdf');
                        }

                        $filename = 'DANFE-' . ($record->document_number ?? $record->id) . '.pdf';

                        return response()->streamDownload(function () use ($pdf) {
                            echo base64_decode($pdf);
                        }, $filename, ['Content-Type' => 'application/pdf']);
                    }),

                Action::make('emitir_nfse')
                    ->label('Emitir NFS-e')
                    ->icon(Heroicon::PaperAirplane)
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalHeading('Emitir Nota Fiscal de Serviço')
                    ->modalDescription('O envio é assíncrono. Após confirmação, a NFS-e será processada em segundo plano.')
                    ->visible(fn(FiscalDocument $record) => $record->isNfse() && (! $record->nfseSent() || $record->isNfseRejected()))
                    ->action(function (FiscalDocument $record): void {
                        $service = app(NfseDocumentService::class);
                        $service->emitir($record, Auth::id());

                        if ($service->isSuccess()) {
                            Notification::make()->title($service->getMessage())->success()->send();
                            return;
                        }

                        Notification::make()->title($service->getMessage())->danger()->send();
                    }),

                Action::make('consultar_nfse')
                    ->label('Consultar NFS-e')
                    ->icon(Heroicon::MagnifyingGlass)
                    ->color('warning')
                    ->visible(fn(FiscalDocument $record) => $record->isNfse())// && $record->isNfseInProcessing())
                    ->action(function (FiscalDocument $record): void {
                        $service = app(NfseDocumentService::class);
                        $service->consultar($record, Auth::id());

                        if ($service->isSuccess()) {
                            Notification::make()->title($service->getMessage())->success()->send();
                            return;
                        }

                        Notification::make()->title($service->getMessage())->danger()->send();
                    }),

                Action::make('preview_nfse')
                    ->label('Preview')
                    ->icon(Heroicon::Eye)
                    ->color('gray')
                    ->visible(fn(FiscalDocument $record) => $record->isNfse())
                    ->modalHeading('Preview da NFS-e')
                    ->modalContent(function (FiscalDocument $record): \Illuminate\Contracts\Support\Htmlable {
                        $service = app(NfseDocumentService::class);
                        $data    = $service->preview($record, Auth::id());

                        if (! $data || ! $data['pdf']) {
                            return new HtmlString(
                                '<p class="text-red-500">' . ($service->getMessage() ?: 'Não foi possível gerar o preview.') . '</p>'
                            );
                        }

                        return new HtmlString(
                            '<iframe src="data:application/pdf;base64,' . $data['pdf'] . '" width="100%" height="600px" style="border:none;"></iframe>'
                        );
                    })
                    ->modalWidth('6xl'),

                Action::make('pdf_nfse')
                    ->label('Download PDF')
                    ->icon(Heroicon::ArrowDownTray)
                    ->color('success')
                    ->visible(fn(FiscalDocument $record) => $record->isNfse() && $record->isNfseAuthorized())
                    ->action(function (FiscalDocument $record): StreamedResponse {
                        $service = app(NfseDocumentService::class);
                        $pdf     = $service->pdf($record, Auth::id());

                        if (! $pdf) {
                            Notification::make()->title($service->getMessage())->danger()->send();
                            return response()->streamDownload(fn() => null, 'nfse.pdf');
                        }

                        $filename = 'NFSE-' . ($record->document_number ?? $record->id) . '.pdf';

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
                    ->visible(fn(FiscalDocument $record) => $record->isNfse() && $record->isNfseAuthorized())
                    ->schema([
                        Textarea::make('motivo')
                            ->label('Motivo do Cancelamento')
                            ->required()
                            ->minLength(15)
                            ->maxLength(255),
                    ])
                    ->action(function (FiscalDocument $record, array $data): void {
                        $service = app(NfseDocumentService::class);
                        $service->cancelar($record, $data['motivo'], Auth::id());

                        if ($service->isSuccess()) {
                            Notification::make()->title($service->getMessage())->success()->send();
                            return;
                        }

                        Notification::make()->title($service->getMessage())->danger()->send();
                    }),

                DeleteAction::make()
                    ->label('Excluir Documento')
                    ->icon(Heroicon::Trash)
                    ->requiresConfirmation()
                    ->modalHeading('Excluir documento fiscal')
                    ->modalDescription('A exclusão remove o documento e seus vínculos. Esta ação não pode ser desfeita.')
                    ->using(function (Model $record): bool {
                        if (! $record instanceof FiscalDocument) {
                            return false;
                        }

                        if (! $this->validateDeleteViaNfService($record)) {
                            return false;
                        }

                        $service = app(FiscalDocumentService::class);
                        $result  = $service->delete($record);

                        if ($service->hasError() || ! $result) {
                            Notification::make()
                                ->title($service->getMessageUser() ?: 'Não foi possível excluir o documento fiscal.')
                                ->danger()
                                ->send();

                            return false;
                        }

                        Notification::make()
                            ->title($service->getMessage() ?: 'Documento fiscal excluído com sucesso.')
                            ->success()
                            ->send();

                        return true;
                    }),
            ])->button(),
        ];
    }

    private function validateDeleteViaNfService(FiscalDocument $record): bool
    {
        if ($record->isNfse()) {
            $service = app(NfseDocumentService::class);

            if (! $service->canDelete($record)) {
                Notification::make()
                    ->title($service->getMessage() ?: 'Documento não pode ser excluído no estado atual.')
                    ->danger()
                    ->send();

                return false;
            }

            return true;
        }

        $service = app(NfeDocumentService::class);

        if (! $service->canDelete($record)) {
            Notification::make()
                ->title($service->getMessage() ?: 'Documento não pode ser excluído no estado atual.')
                ->danger()
                ->send();

            return false;
        }

        return true;
    }

    private function buildAdditionalPurchaseInformation(array $data): ?string
    {
        $payload = [
            'nota_empenho' => trim((string) ($data['additional_purchase_information_nota_empenho'] ?? '')),
            'pedido' => trim((string) ($data['additional_purchase_information_pedido'] ?? '')),
            'contrato' => trim((string) ($data['additional_purchase_information_contrato'] ?? '')),
        ];

        $payload = array_filter($payload, fn (string $value): bool => $value !== '');

        if ($payload === []) {
            return null;
        }

        return json_encode($payload, JSON_UNESCAPED_UNICODE);
    }

    private function parseAdditionalPurchaseInformation(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
}
