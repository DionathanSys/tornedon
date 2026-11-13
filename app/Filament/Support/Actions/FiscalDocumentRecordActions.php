<?php

namespace App\Filament\Support\Actions;

use App\Filament\Clusters\Sales\Resources\FiscalDocuments\Actions\ConfigurePurchaseReturnSettlementAction;
use App\Models\FiscalDocument;
use App\Models\User;
use App\Notification\NotifyService as notify;
use App\Services\Access\CompanyAuthorization;
use App\Services\Access\UserAccessService;
use App\Services\FiscalDocument\Actions\ProcessAuthorizedNfeStockMovementsAction;
use App\Services\FiscalDocument\Actions\ReconcileNfseRpsSequenceAction;
use App\Services\FiscalDocument\FiscalCancellationPolicy;
use App\Services\FiscalDocument\FiscalDocumentIssueService;
use App\Services\FiscalDocument\FiscalDocumentService;
use App\Services\FiscalDocument\NfeDocumentService;
use App\Services\FiscalDocument\NfseDocumentService;
use App\Services\FiscalDocument\XmlExport\DownloadFiscalDocumentXmlAction;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class FiscalDocumentRecordActions
{
    /**
     * @return array<int, Action|EditAction>
     */
    public static function make(bool $includeEditAction = false, bool $includeManagementActions = false): array
    {
        $actions = [
            Action::make('emitir')
                ->label('Emitir NF-e')
                ->icon(Heroicon::PaperAirplane)
                ->color('primary')
                ->requiresConfirmation()
                ->modalHeading('Emitir Nota Fiscal Eletrônica')
                ->modalDescription('O envio é assíncrono. Após confirmação, a NF-e será processada em segundo plano.')
                ->visible(fn (FiscalDocument $record) => ! $record->canceled && $record->isNfe() && ! $record->isNfeQueued() && (! $record->isNfeSent() || $record->isNfeRejected()))
                ->action(function (FiscalDocument $record): void {
                    $record = self::resolveFullRecord($record);

                    $service = app(NfeDocumentService::class);
                    $service->emitir($record, Auth::id());

                    if ($service->isSuccess()) {
                        notify::success($service->getMessage());
                    } else {
                        notify::error('Falha durante processamento', $service->getMessageUser() ?: $service->getMessage());
                    }
                }),

            Action::make('consultar')
                ->label('Consultar SEFAZ')
                ->icon(Heroicon::MagnifyingGlass)
                ->color('warning')
                ->visible(fn (FiscalDocument $record) => $record->isNfe() && ($record->isNfeInProcessing() || $record->hasOpenFiscalIssue() || (($user = Auth::user()) instanceof User && $user->canManageFiscalOperations())))
                ->action(function (FiscalDocument $record): void {
                    $record = self::resolveFullRecord($record);

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
                ->visible(fn (FiscalDocument $record) => $record->isNfe() && ! $record->isNfeAuthorized() && ! $record->isNfeCanceled())
                ->modalHeading('Preview da NF-e')
                ->modalContent(function (FiscalDocument $record): Htmlable {
                    $record = self::resolveFullRecord($record);

                    $service = app(NfeDocumentService::class);
                    $data = $service->preview($record, Auth::id());

                    if (! $data || ! $data['pdf']) {
                        return self::previewError($record, $service->getMessage() ?: 'Não foi possível gerar o preview.');
                    }

                    return new HtmlString(
                        '<iframe src="data:application/pdf;base64,'.$data['pdf'].'" width="100%" height="600px" style="border:none;"></iframe>'
                    );
                })
                ->action(function (FiscalDocument $record): void {
                    $record = self::resolveFullRecord($record);

                    $service = app(NfeDocumentService::class);
                    $service->emitir($record, Auth::id());

                    if ($service->isSuccess()) {
                        notify::success('NF-e enfileirada para emissão. A nota será enviada automaticamente quando chegar sua vez na fila da empresa/série.');

                        return;
                    }

                    notify::error('Falha durante processamento', $service->getMessageUser() ?: $service->getMessage());
                })
                ->modalSubmitActionLabel('Emitir')
                ->modalWidth('6xl'),

            Action::make('danfe')
                ->label('Download DANFE')
                ->icon(Heroicon::ArrowDownTray)
                ->color('success')
                ->visible(fn (FiscalDocument $record) => $record->isNfe() && $record->isNfeAuthorized())
                ->action(function (FiscalDocument $record): StreamedResponse {
                    $record = self::resolveFullRecord($record);

                    $service = app(NfeDocumentService::class);
                    $pdf = $service->danfe($record, Auth::id());

                    if (! $pdf) {
                        notify::error('Falha durante processamento', $service->getMessageUser() ?: $service->getMessage());

                        return response()->streamDownload(fn () => null, 'danfe.pdf');
                    }

                    $filename = 'DANFE-'.($record->document_number ?? $record->id).'.pdf';

                    return response()->streamDownload(function () use ($pdf) {
                        echo base64_decode($pdf);
                    }, $filename, ['Content-Type' => 'application/pdf']);
                }),

            self::downloadXmlAction(),

            Action::make('cancelar_nfe')
                ->label('Cancelar NF-e')
                ->icon(Heroicon::XCircle)
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Cancelar NF-e')
                ->modalDescription('Esta ação não pode ser desfeita. A NF-e será cancelada na SEFAZ.')
                ->tooltip(fn (FiscalDocument $record): ?string => app(FiscalCancellationPolicy::class)->evaluate($record)['message'])
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
                    $record = self::resolveFullRecord($record);

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
                    $record = self::resolveFullRecord($record);

                    $service = app(NfeDocumentService::class);
                    $service->corrigir(
                        $record,
                        $data['justificativa'],
                        filled($data['sequencial'] ?? null) ? (int) $data['sequencial'] : null,
                        Auth::id()
                    );

                    if ($service->isSuccess()) {
                        notify::success($service->getMessage());
                    } else {
                        notify::error($service->getMessage());
                    }
                }),

            Action::make('baixar_carta_correcao_nfe')
                ->label('Baixar Carta de Correção')
                ->icon(Heroicon::DocumentArrowDown)
                ->color('info')
                ->visible(fn (FiscalDocument $record) => $record->isNfe() && self::hasCorrections($record))
                ->schema([
                    Select::make('correction_index')
                        ->label('Carta de Correção')
                        ->options(fn (FiscalDocument $record): array => self::buildCorrectionOptions($record))
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
                    $record = self::resolveFullRecord($record);

                    $correction = self::getSelectedCorrection($record, (int) $data['correction_index']);

                    if ($correction === null) {
                        notify::error('Carta de correção não encontrada para download.');

                        return response()->streamDownload(fn () => null, 'carta-correcao-nfe.txt');
                    }

                    return self::downloadCorrectionFile($record, $correction, $data['download_type']);
                }),

            Action::make('emitir_nfse')
                ->label('Emitir NFS-e')
                ->icon(Heroicon::PaperAirplane)
                ->color('primary')
                ->requiresConfirmation()
                ->modalHeading('Emitir Nota Fiscal de Serviço')
                ->modalDescription('O envio é assíncrono. Após confirmação, a NFS-e será processada em segundo plano.')
                ->visible(fn (FiscalDocument $record) => ! $record->canceled && $record->isNfse() && ! $record->isNfseQueued() && (! $record->isNfseSent() || $record->isNfseRejected()))
                ->action(function (FiscalDocument $record): void {
                    $record = self::resolveFullRecord($record);

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
                ->visible(fn (FiscalDocument $record) => $record->isNfse() && ($record->isNfseInProcessing() || $record->hasOpenFiscalIssue()))
                ->action(function (FiscalDocument $record): void {
                    $record = self::resolveFullRecord($record);

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
                ->modalContent(function (FiscalDocument $record): Htmlable {
                    $record = self::resolveFullRecord($record);

                    $service = app(NfseDocumentService::class);
                    $data = $service->preview($record, Auth::id());

                    if (! $data || ! $data['pdf']) {
                        return self::previewError($record, $service->getMessage() ?: 'Não foi possível gerar o preview.');
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
                    $record = self::resolveFullRecord($record);

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
                        ->live()
                        ->minLength(fn (FiscalDocument $record): int => self::isNationalNfse($record) ? 15 : 1)
                        ->maxLength(fn (FiscalDocument $record): int => self::isNationalNfse($record) ? 255 : 80)
                        ->helperText(function (FiscalDocument $record, callable $get): string {
                            $maxLength = self::isNationalNfse($record) ? 255 : 80;
                            $minLength = self::isNationalNfse($record) ? 15 : 1;
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
                    $record = self::resolveFullRecord($record);

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

        $actions[] = ManageFiscalDocumentIssueAction::make();
        $actions[] = Action::make('reprocessar_estoque')->label('Reprocessar estoque da NF-e')->icon(Heroicon::ArrowPath)->color('warning')->requiresConfirmation()
            ->visible(fn (FiscalDocument $record, $livewire): bool => $record->isNfeAuthorized() && $record->hasPendingStockEffect()
                && (method_exists($livewire, 'canUseFiscalDocumentAction') ? $livewire->canManageFiscalIssues() : app(FiscalDocumentIssueService::class)->canManage(Filament::auth()->user(), $record)))
            ->modalDescription('Revalida o saldo e tenta concluir os efeitos de estoque pendentes, sem reenviar a nota e sem duplicar saídas já processadas.')
            ->action(function (FiscalDocument $record): void {
                $record = self::resolveFullRecord($record);
                abort_unless(app(FiscalDocumentIssueService::class)->canManage(Filament::auth()->user(), $record), 403);
                $action = app(ProcessAuthorizedNfeStockMovementsAction::class);
                if ($action->execute($record, Filament::auth()->id())) {
                    notify::success('Efeitos de estoque da NF-e processados.');
                } else {
                    notify::error($action->getMessage());
                }
            });

        if ($includeManagementActions) {
            array_unshift($actions, ConfigurePurchaseReturnSettlementAction::make());
            $actions[] = self::reconcileRpsAction();
            $actions[] = DeleteAction::make()
                ->label('Excluir Documento')->icon(Heroicon::Trash)->requiresConfirmation()
                ->visible(fn (FiscalDocument $record): bool => ! $record->isNfeSent() && ! $record->isNfseSent() && ! $record->isNfeQueued() && ! $record->isNfseQueued() && ! $record->hasOpenFiscalIssue())
                ->using(function (FiscalDocument $record): bool {
                    $record = self::resolveFullRecord($record);
                    $fiscalService = app($record->isNfse() ? NfseDocumentService::class : NfeDocumentService::class);
                    if (! $fiscalService->canDelete($record)) {
                        notify::error($fiscalService->getMessage());

                        return false;
                    }
                    $service = app(FiscalDocumentService::class);
                    $result = $service->delete($record);
                    if (! $result || $service->hasError()) {
                        notify::error($service->getMessageUser() ?: 'Não foi possível excluir o documento fiscal.');
                    }

                    return (bool) $result;
                });
        }

        if ($includeEditAction) {
            $actions[] = EditAction::make();
        }

        foreach ($actions as $action) {
            $ability = match ($action->getName()) {
                'emitir', 'emitir_nfse' => 'issue',
                'cancelar_nfe', 'cancelar_nfse' => 'cancel',
                'consultar', 'consultar_nfse', 'corrigir_nfe', 'reconciliar_rps_nfse', 'tratar_pendencia_fiscal', 'reprocessar_estoque', 'configurePurchaseReturnSettlement', 'edit' => 'update',
                'delete' => 'delete',
                default => 'view',
            };
            $action->authorize(fn (FiscalDocument $record, $livewire): bool => method_exists($livewire, 'canUseFiscalDocumentAction')
                ? $livewire->canUseFiscalDocumentAction($record, $ability) : self::canUse($record, $ability))
                ->beforeFormFilled(fn (FiscalDocument $record) => abort_unless(self::canUse($record, $ability), 403))
                ->before(fn (FiscalDocument $record) => abort_unless(self::canUse($record, $ability), 403))
                ->after(function ($livewire) use ($action): void {
                    if ($action->getName() !== 'delete' && method_exists($livewire, 'refreshFiscalDocumentState')) {
                        $livewire->refreshFiscalDocumentState();
                    }
                    $livewire->dispatch('invoice-fiscal-state-changed');
                    $livewire->dispatch('invoice-fiscal-documents-refresh');
                });
        }

        return $actions;
    }

    public static function downloadXmlAction(): Action
    {
        return Action::make('download_xml')
            ->label('Download XML')
            ->icon(Heroicon::ArrowDownTray)
            ->color('success')
            ->visible(fn (FiscalDocument $record) => ($record->isNfe() && $record->isNfeAuthorized())
                || ($record->isNfse() && $record->isNfseAuthorized()))
            ->action(function (FiscalDocument $record): ?StreamedResponse {
                $record = self::resolveFullRecord($record);
                $action = app(DownloadFiscalDocumentXmlAction::class);
                $xml = $action->execute($record);

                if ($xml === null) {
                    notify::error($action->getMessage() ?: 'Não foi possível baixar o XML da nota fiscal.');

                    return null;
                }

                $prefix = $record->isNfse() ? 'NFSE-' : 'NFE-';
                $filename = $prefix.($record->document_number ?? $record->id).'.xml';

                return response()->streamDownload(function () use ($xml): void {
                    echo $xml;
                }, $filename, ['Content-Type' => 'application/xml']);
            });
    }

    private static function isNationalNfse(FiscalDocument $record): bool
    {
        return ($record->nfse_model?->value ?? $record->nfse_model) === 'nacional';
    }

    private static function hasCorrections(FiscalDocument $record): bool
    {
        if (array_key_exists('has_corrections', $record->getAttributes())) {
            return (bool) $record->getAttribute('has_corrections');
        }

        return self::buildCorrectionOptions($record) !== [];
    }

    private static function resolveFullRecord(FiscalDocument $record): FiscalDocument
    {
        $record = FiscalDocument::query()->where('company_id', Filament::getTenant()?->id)->findOrFail($record->getKey());

        return $record;
    }

    private static function canUse(FiscalDocument $record, string $ability): bool
    {
        $user = Filament::auth()->user();
        $company = Filament::getTenant();
        if (! $user || ! $company || (int) $record->company_id !== (int) $company->id) {
            return false;
        }
        $access = app(CompanyAuthorization::class);

        return app(UserAccessService::class)->allows($user, $company, 'fiscal_documents.'.$ability)
            && (! $access->requiresRoleAuthorization($user, $company, Filament::getCurrentPanel()?->getId()) || $access->allowsRecord($user, $company, 'fiscal_documents.'.$ability, $record));
    }

    private static function reconcileRpsAction(): Action
    {
        return Action::make('reconciliar_rps_nfse')->label('Conciliar RPS')->icon(Heroicon::WrenchScrewdriver)->color('warning')
            ->visible(fn (FiscalDocument $record): bool => $record->isNfse() && $record->isNfsePendingReconciliation())
            ->schema([
                Textarea::make('reason')->label('Justificativa')->required()->minLength(15)->rows(4),
                Select::make('resolution')->label('Destino do documento')->options(['keep' => 'Manter em conciliação com o RPS atual', 'clear' => 'Limpar RPS do documento para novo envio'])->default('clear')->required()->native(false),
            ])->action(function (FiscalDocument $record, array $data): void {
                $action = app(ReconcileNfseRpsSequenceAction::class);
                $action->execute(self::resolveFullRecord($record), $data['reason'], $data['resolution'] === 'clear');
                if ($action->isSuccess()) {
                    notify::success('Conciliação de RPS registrada com sucesso.');
                } else {
                    notify::error($action->getMessage() ?: 'Não foi possível conciliar o RPS.');
                }
            });
    }

    private static function previewError(FiscalDocument $record, string $message): HtmlString
    {
        $record->refresh();
        $latest = collect($record->errors_messages ?? [])->last();
        $detail = is_array($latest) ? ($latest['mensagem'] ?? null) : null;

        return new HtmlString('<div class="space-y-4"><p class="text-danger-600">'.e($message).'</p>'
            .($detail ? '<p>Erro atual</p><pre class="whitespace-pre-wrap">'.e(preg_replace('/<br\s*\/?\>/i', PHP_EOL, $detail)).'</pre>' : '').'</div>');
    }

    /**
     * @return array<string, string>
     */
    private static function buildCorrectionOptions(FiscalDocument $record): array
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

    private static function getSelectedCorrection(FiscalDocument $record, int $index): ?array
    {
        $corrections = data_get($record->nfe_payload, 'correcoes', []);

        if (! is_array($corrections)) {
            return null;
        }

        $correction = $corrections[$index] ?? null;

        return is_array($correction) ? $correction : null;
    }

    private static function downloadCorrectionFile(FiscalDocument $record, array $correction, string $downloadType): StreamedResponse
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
}
