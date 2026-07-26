<?php

namespace App\Filament\Clusters\Sales\Resources\FiscalDocuments\Pages;

use App\Filament\Clusters\Sales\Resources\FiscalDocuments\Actions\ConfigurePurchaseReturnSettlementAction;
use App\Filament\Clusters\Sales\Resources\FiscalDocuments\FiscalDocumentResource;
use App\Models\FiscalDocument;
use App\Notification\NotifyService as notify;
use App\Services\FiscalDocument\Actions\ReconcileNfseRpsSequenceAction;
use App\Services\FiscalDocument\FiscalDocumentService;
use App\Services\FiscalDocument\NfeDocumentService;
use App\Services\FiscalDocument\NfseDocumentService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
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

    protected string $view = 'filament.clusters.sales.resources.fiscal-documents.pages.edit-fiscal-document';

    /**
     * @var array<int, string>
     */
    private const REALTIME_REFRESH_STATE_PATHS = [
        'status',
        'nfe_status',
        'nfse_status',
        'document_number',
        'document_series',
        'rps_number',
        'rps_series',
        'document_key',
        'errors_messages',
        'nfe_payload',
        'emission_requested_at',
        'emission_attempted_at',
        'confirmed_at',
        'confirmed_by',
        'canceled_at',
        'updated_at',
    ];

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $record = $this->getRecord();

        $data['freight_data'] = $record->freight_data;
        $data['payment_data'] = $record->payment_data;
        $data['tax_data'] = $record->tax_data;
        $data['nfe_payload'] = $record->nfe_payload;
        $data['nfse_payload'] = $record->nfse_payload;
        $data['fiscal_payload_preview'] = $this->payloadJson($record);

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

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $service = app(FiscalDocumentService::class);
        $updated = $service->update($record, $data, (int) Auth::id());

        if ($service->hasError() || $updated === null) {
            notify::error(
                message: $service->getMessageUser(),
                errorCode: $service->getErrorCode()
            );

            $this->halt();
        }

        return $updated;
    }

    public function getAutoRefreshInterval(): ?string
    {
        return $this->isAutoRefreshEnabled() ? '10s' : null;
    }

    public function isAutoRefreshEnabled(): bool
    {
        $record = $this->getRecord();

        return $record->isNfse()
            ? $record->isNfseQueued() || $record->isNfseInProcessing()
            : $record->isNfeQueued() || $record->isNfeInProcessing();
    }

    public function refreshFiscalDocumentState(): void
    {
        $this->syncFiscalDocumentState();
    }

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                ConfigurePurchaseReturnSettlementAction::make(),
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
                        $this->syncFiscalDocumentState();

                        if ($service->isSuccess()) {
                            notify::success('NF-e enfileirada para emissão. A nota será enviada automaticamente quando chegar sua vez na fila da empresa/série.');

                            return;
                        }

                        notify::error('Falha durante processamento', $service->getMessageUser() ?: $service->getMessage());
                    })
                    ->successRedirectUrl(fn (FiscalDocument $record) => FiscalDocumentResource::getUrl('edit', ['record' => $record])),

                Action::make('consultar')
                    ->label('Consultar SEFAZ')
                    ->icon(Heroicon::MagnifyingGlass)
                    ->color('warning')
                    ->visible(fn (FiscalDocument $record) => ($record->isNfe() && $record->isNfeInProcessing()) || Auth::user()->is_admin)
                    ->action(function (FiscalDocument $record): void {
                        $service = app(NfeDocumentService::class);
                        $service->consultar($record, Auth::id());
                        $this->syncFiscalDocumentState();

                        if ($service->isSuccess()) {
                            notify::success('Consulta da NF-e realizada. O retorno mais recente da SEFAZ já foi refletido no formulário. Se a nota continuar em processamento, a página seguirá atualizando automaticamente.');

                            return;
                        }

                        notify::error('Falha durante processamento', $service->getMessageUser() ?: $service->getMessage());
                    }),

                Action::make('preview')
                    ->label('Preview')
                    ->icon(Heroicon::Eye)
                    ->color('gray')
                    ->visible(fn (FiscalDocument $record) => $record->isNfe() && ! $record->isNfeAuthorized())
                    ->modalHeading('Preview da NF-e')
                    ->modalContent(function (FiscalDocument $record): \Illuminate\Contracts\Support\Htmlable {
                        $service = app(NfeDocumentService::class);
                        $data = $service->preview($record, Auth::id());

                        if (! $data || ! $data['pdf']) {
                            $record->refresh();

                            return $this->buildPreviewErrorModalContent(
                                $service->getMessage() ?: 'Não foi possível gerar o preview.',
                                $record
                            );
                        }

                        return new HtmlString(
                            '<iframe src="data:application/pdf;base64,'.$data['pdf'].'" width="100%" height="600px" style="border:none;"></iframe>'
                        );
                    })
                    ->modalSubmitActionLabel('Emitir')
                    ->modalWidth('6xl')
                    ->action(function (FiscalDocument $record): void {
                        $service = app(NfeDocumentService::class);
                        $service->emitir($record, Auth::id());
                        $this->syncFiscalDocumentState();

                        if ($service->isSuccess()) {
                            notify::success('NF-e enfileirada para emissão. A nota será enviada automaticamente quando chegar sua vez na fila da empresa/série.');

                            return;
                        }

                        notify::error('Falha durante processamento', $service->getMessageUser() ?: $service->getMessage());
                    })
                    ->after(fn () => $this->refreshFormData(['errors_messages'])),

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
                        $this->syncFiscalDocumentState();

                        if ($service->isSuccess()) {
                            notify::success($service->getMessage());

                            return;
                        }

                        notify::error($service->getMessage());
                    }),

                Action::make('corrigir_nfe')
                    ->label('Carta de Correção')
                    ->icon(Heroicon::PencilSquare)
                    ->color('warning')
                    ->modalHeading('Emitir Carta de Correção')
                    ->modalDescription('Use este evento apenas para correções permitidas pela legislação da NF-e.')
                    ->visible(fn (FiscalDocument $record) => $record->isNfe() && $record->isNfeAuthorized())
                    ->schema([
                        Textarea::make('justificativa')
                            ->label('Correção')
                            ->required()
                            ->live()
                            ->minLength(15)
                            ->maxLength(1000)
                            ->helperText(fn (callable $get): string => sprintf(
                                'Mínimo de 15 caracteres. %d/1000 caracteres digitados.',
                                mb_strlen((string) ($get('justificativa') ?? ''))
                            ))
                            ->rows(5),
                        TextInput::make('sequencial')
                            ->label('Sequencial do Evento')
                            ->helperText('Deixe em branco para a API controlar automaticamente. Preencha apenas se já houve carta de correção emitida fora do sistema.')
                            ->numeric()
                            ->minValue(1),
                    ])
                    ->action(function (FiscalDocument $record, array $data): void {
                        $service = app(NfeDocumentService::class);
                        $service->corrigir(
                            $record,
                            $data['justificativa'],
                            filled($data['sequencial'] ?? null) ? (int) $data['sequencial'] : null,
                            Auth::id()
                        );
                        $this->syncFiscalDocumentState();

                        if ($service->isSuccess()) {
                            notify::success($service->getMessage());

                            return;
                        }

                        notify::error($service->getMessage());
                    }),

                Action::make('baixar_carta_correcao_nfe')
                    ->label('Baixar Carta de Correção')
                    ->icon(Heroicon::DocumentArrowDown)
                    ->color('info')
                    ->visible(fn (FiscalDocument $record) => $record->isNfe() && $this->buildCorrectionOptions($record) !== [])
                    ->schema([
                        Select::make('correction_index')
                            ->label('Carta de Correção')
                            ->options(fn (FiscalDocument $record): array => $this->buildCorrectionOptions($record))
                            ->required()
                            ->native(false),
                        Select::make('download_type')
                            ->label('Arquivo')
                            ->options([
                                'pdf' => 'PDF',
                                'xml' => 'XML',
                            ])
                            ->default('pdf')
                            ->required()
                            ->native(false),
                    ])
                    ->action(function (FiscalDocument $record, array $data): StreamedResponse {
                        $correction = $this->getSelectedCorrection($record, (int) $data['correction_index']);

                        if ($correction === null) {
                            notify::error('Carta de correção não encontrada para download.');

                            return response()->streamDownload(fn () => null, 'carta-correcao-nfe.txt');
                        }

                        return $this->downloadCorrectionFile($record, $correction, $data['download_type']);
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
                        $this->syncFiscalDocumentState();

                        if ($service->isSuccess()) {
                            notify::success('NFS-e enfileirada para emissão. A nota será enviada automaticamente quando chegar sua vez na fila da empresa/série.');

                            return;
                        }

                        notify::error($service->getMessage());
                    }),

                Action::make('consultar_nfse')
                    ->label('Consultar NFS-e')
                    ->icon(Heroicon::MagnifyingGlass)
                    ->color('warning')
                    ->visible(fn (FiscalDocument $record) => $record->isNfse() && $record->isNfseInProcessing())
                    ->action(function (FiscalDocument $record): void {
                        $service = app(NfseDocumentService::class);
                        $service->consultar($record, Auth::id());
                        $this->syncFiscalDocumentState();

                        if ($service->isSuccess()) {
                            notify::success('Consulta da NFS-e realizada. O retorno mais recente da prefeitura já foi refletido no formulário. Se a nota continuar em processamento, a página seguirá atualizando automaticamente.');

                            return;
                        }

                        notify::error($service->getMessage());
                    }),

                Action::make('reconciliar_rps_nfse')
                    ->label('Conciliar RPS')
                    ->icon(Heroicon::WrenchScrewdriver)
                    ->color('warning')
                    ->visible(fn (FiscalDocument $record) => $record->isNfse() && $record->isNfsePendingReconciliation())
                    ->modalHeading('Conciliar RPS da NFS-e')
                    ->modalDescription('Registre o motivo da conciliação. Se desejar, limpe o RPS atual do documento para permitir novo envio com outro número.')
                    ->schema([
                        Textarea::make('reason')
                            ->label('Justificativa')
                            ->required()
                            ->minLength(15)
                            ->rows(4),
                        Select::make('resolution')
                            ->label('Destino do documento')
                            ->options([
                                'keep' => 'Manter em conciliação com o RPS atual',
                                'clear' => 'Limpar RPS do documento para novo envio',
                            ])
                            ->default('clear')
                            ->required()
                            ->native(false),
                    ])
                    ->action(function (FiscalDocument $record, array $data): void {
                        $action = app(ReconcileNfseRpsSequenceAction::class);

                        $action->execute(
                            $record,
                            (string) $data['reason'],
                            ($data['resolution'] ?? 'clear') === 'clear'
                        );

                        $this->syncFiscalDocumentState();

                        if ($action->isSuccess()) {
                            notify::success('Conciliação de RPS registrada com sucesso.');

                            return;
                        }

                        notify::error($action->getMessage() ?: 'Não foi possível conciliar o RPS da NFS-e.');
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
                            $record->refresh();

                            return $this->buildPreviewErrorModalContent(
                                $service->getMessage() ?: 'Não foi possível gerar o preview.',
                                $record
                            );
                        }

                        return new HtmlString(
                            '<iframe src="data:application/pdf;base64,'.$data['pdf'].'" width="100%" height="600px" style="border:none;"></iframe>'
                        );
                    })
                    ->modalSubmitAction(false)
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
                            Notification::make()->title($service->getMessage())->danger()->send();

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
                            ->live()
                            ->minLength(fn (FiscalDocument $record): int => $this->isNationalNfse($record) ? 15 : 1)
                            ->maxLength(fn (FiscalDocument $record): int => $this->isNationalNfse($record) ? 255 : 80)
                            ->helperText(function (FiscalDocument $record, callable $get): string {
                                $maxLength = $this->isNationalNfse($record) ? 255 : 80;
                                $minLength = $this->isNationalNfse($record) ? 15 : 1;
                                $currentLength = mb_strlen((string) ($get('motivo_cancelamento') ?? ''));

                                return sprintf(
                                    'Informe entre %d e %d caracteres. %d/%d caracteres digitados.',
                                    $minLength,
                                    $maxLength,
                                    $currentLength,
                                    $maxLength,
                                );
                            })
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
                        $result = $service->delete($record);

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

    private function payloadJson(FiscalDocument $record): string
    {
        $payload = $record->isNfse() ? $record->nfse_payload : $record->nfe_payload;

        if (! is_array($payload) || $payload === []) {
            return '{}';
        }

        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    private function isNationalNfse(FiscalDocument $record): bool
    {
        return ($record->nfse_model?->value ?? $record->nfse_model) === 'nacional';
    }

    private function syncFiscalDocumentState(): void
    {
        $this->getRecord()->refresh();
        $this->refreshFormData(self::REALTIME_REFRESH_STATE_PATHS);
    }

    private function buildPreviewErrorModalContent(string $fallbackMessage, FiscalDocument $record): HtmlString
    {
        $currentError = $this->getLatestPersistedErrorMessage($record);

        $content = '<div class="space-y-4">'
            .'<p class="text-sm text-danger-600">'.e($fallbackMessage).'</p>';

        if ($currentError !== null) {
            $content .= '<div class="rounded-lg border border-danger-200 bg-danger-50 p-4 dark:border-danger-800 dark:bg-danger-950/30">'
                .'<p class="text-sm font-medium text-danger-700 dark:text-danger-300">Erro atual</p>'
                .'<pre class="mt-2 max-w-full overflow-x-auto whitespace-pre-wrap break-words font-sans text-sm text-danger-700 dark:text-danger-200">'.e($currentError).'</pre>'
                .'</div>';
        }

        $content .= '</div>';

        return new HtmlString($content);
    }

    private function getLatestPersistedErrorMessage(FiscalDocument $record): ?string
    {
        $errors = $record->errors_messages;

        if (! is_array($errors) || $errors === []) {
            return null;
        }

        $latestError = end($errors);

        if (! is_array($latestError)) {
            return null;
        }

        $message = $latestError['mensagem'] ?? null;

        if (! is_string($message) || trim($message) === '') {
            return null;
        }

        return preg_replace('/<br\s*\/?\>/i', PHP_EOL, $message);
    }

    /**
     * @return array<string, string>
     */
    private function buildCorrectionOptions(FiscalDocument $record): array
    {
        $corrections = data_get($record->nfe_payload, 'correcoes', []);

        if (! is_array($corrections)) {
            return [];
        }

        $options = [];

        foreach ($corrections as $index => $correction) {
            if (! is_array($correction)) {
                continue;
            }

            $number = $correction['sequencial'] ?? ((int) $index + 1);
            $protocol = $correction['protocolo'] ?? null;

            $label = 'CC-e '.$number;

            if (filled($protocol)) {
                $label .= ' - Prot. '.$protocol;
            }

            $options[(string) $index] = $label;
        }

        return $options;
    }

    private function getSelectedCorrection(FiscalDocument $record, int $index): ?array
    {
        $corrections = data_get($record->nfe_payload, 'correcoes', []);

        if (! is_array($corrections)) {
            return null;
        }

        $correction = $corrections[$index] ?? null;

        return is_array($correction) ? $correction : null;
    }

    private function downloadCorrectionFile(FiscalDocument $record, array $correction, string $downloadType): StreamedResponse
    {
        $isXml = $downloadType === 'xml';
        $contentBase64 = $isXml
            ? ($correction['xml_base64'] ?? null)
            : ($correction['pdf_base64'] ?? null);

        if (! is_string($contentBase64) || trim($contentBase64) === '') {
            notify::error('O arquivo da carta de correção selecionada não está disponível para download.');

            return response()->streamDownload(fn () => null, $isXml ? 'carta-correcao-nfe.xml' : 'carta-correcao-nfe.pdf');
        }

        $number = $correction['sequencial'] ?? '1';
        $documentNumber = $record->document_number ?? $record->id;
        $filename = 'CCE-NFE-'.$documentNumber.'-'.$number.'.'.($isXml ? 'xml' : 'pdf');

        return response()->streamDownload(function () use ($contentBase64) {
            echo base64_decode($contentBase64);
        }, $filename, [
            'Content-Type' => $isXml ? 'application/xml' : 'application/pdf',
        ]);
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
