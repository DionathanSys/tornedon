<?php

namespace App\Filament\Clusters\Sales\Resources\FiscalDocuments\Tables;

use App\Enum\FiscalDocument\DocumentModel;
use App\Enum\FiscalDocument\NfeStatus;
use App\Enum\FiscalDocument\Status;
use App\Filament\Clusters\Sales\Resources\FiscalDocuments\FiscalDocumentResource;
use App\Models\FiscalDocument;
use App\Services\FiscalDocument\NfeDocumentService;
use App\Services\FiscalDocument\NfseDocumentService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FiscalDocumentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('document_type')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn(?DocumentModel $state) => $state?->description() ?? '—')
                    ->color(fn(?DocumentModel $state) => match ($state) {
                        DocumentModel::NFSE => 'info',
                        default => 'gray',
                    })
                    ->sortable()
                    ->width('1%')
                    ->toggleable(isToggledHiddenByDefault: false),

                Tables\Columns\TextColumn::make('document_number')
                    ->label('Número')
                    ->searchable()
                    ->sortable()
                    ->placeholder('-')
                    ->width('1%')
                    ->toggleable(isToggledHiddenByDefault: false),

                Tables\Columns\TextColumn::make('document_series')
                    ->label('Série')
                    ->sortable()
                    ->placeholder('-')
                    ->width('1%')
                    ->toggleable(isToggledHiddenByDefault: false),

                Tables\Columns\TextColumn::make('rps_series')
                    ->label('RPS Série')
                    ->sortable()
                    ->placeholder('-')
                    ->width('1%')
                    ->toggleable(isToggledHiddenByDefault: false),

                Tables\Columns\TextColumn::make('customer.name')
                    ->label('Cliente')
                    ->searchable()
                    ->sortable()
                    ->wrap()
                    ->lineClamp(2)
                    ->width('1%')
                    ->toggleable(isToggledHiddenByDefault: false),

                Tables\Columns\TextColumn::make('operation_nature')
                    ->label('Natureza')
                    ->limit(25)
                    ->width('1%')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('issued_at')
                    ->label('Emissão')
                    ->date('d/m/Y')
                    ->sortable()
                    ->width('1%')
                    ->toggleable(isToggledHiddenByDefault: false),

                Tables\Columns\TextColumn::make('fiscal_status')
                    ->label('Status Fiscal')
                    ->badge()
                    ->state(
                        fn(FiscalDocument $record): ?NfeStatus => $record->isNfse()
                            ? $record->nfse_status
                            : $record->nfe_status
                    )
                    ->formatStateUsing(fn(?NfeStatus $state) => $state?->description() ?? 'Não enviado')
                    ->color(fn(?NfeStatus $state) => $state?->color() ?? 'gray')
                    ->width('1%')
                    ->toggleable(isToggledHiddenByDefault: false),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn(?Status $state) => $state?->description() ?? '-')
                    ->color(fn(?Status $state) => $state?->color() ?? 'gray')
                    ->toggleable(isToggledHiddenByDefault: false),

                Tables\Columns\TextColumn::make('nfe_protocolo')
                    ->label('Protocolo')
                    ->searchable()
                    ->width('1%')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('document_key')
                    ->label('Chave de Acesso')
                    ->limit(20)
                    ->searchable()
                    ->width('1%')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('confirmed_at')
                    ->label('Confirmado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('pending')
                    ->label('Pendente')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Criado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('document_type')
                    ->label('Tipo de Documento')
                    ->options(DocumentModel::toSelectArray()),

                Tables\Filters\SelectFilter::make('nfe_status')
                    ->label('Status NF-e')
                    ->options(array_merge(
                        ['null' => 'Não enviado'],
                        NfeStatus::toSelectArray()
                    )),

                Tables\Filters\SelectFilter::make('nfse_status')
                    ->label('Status NFS-e')
                    ->options(array_merge(
                        ['null' => 'Não enviada'],
                        NfeStatus::toSelectArray()
                    )),

                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options(Status::toSelectArray()),
            ])
            ->reorderableColumns()
            ->recordActions([
                // ===== NF-e Actions =====
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
                            } else {
                                Notification::make()->title($service->getMessage())->danger()->send();
                            }
                        }),

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
                            } else {
                                Notification::make()->title($service->getMessage())->danger()->send();
                            }
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
                                return new \Illuminate\Support\HtmlString(
                                    '<p class="text-red-500">' . ($service->getMessage() ?: 'Não foi possível gerar o preview.') . '</p>'
                                );
                            }

                            return new \Illuminate\Support\HtmlString(
                                '<iframe src="data:application/pdf;base64,' . $data['pdf'] . '" width="100%" height="600px" style="border:none;"></iframe>'
                            );
                        })
                        ->modalWidth('6xl'),

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

                    // ===== NFS-e Actions =====

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
                            } else {
                                Notification::make()->title($service->getMessage())->danger()->send();
                            }
                        }),

                    Action::make('consultar_nfse')
                        ->label('Consultar NFS-e')
                        ->icon(Heroicon::MagnifyingGlass)
                        ->color('warning')
                        ->visible(fn(FiscalDocument $record) => $record->isNfse() && $record->isNfseInProcessing())
                        ->action(function (FiscalDocument $record): void {
                            $service = app(NfseDocumentService::class);
                            $service->consultar($record, Auth::id());

                            if ($service->isSuccess()) {
                                Notification::make()->title($service->getMessage())->success()->send();
                            } else {
                                Notification::make()->title($service->getMessage())->danger()->send();
                            }
                        }),

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
                                Notification::make()->title($service->getMessage())->success()->send();
                            } else {
                                Notification::make()->title($service->getMessage())->danger()->send();
                            }
                        }),

                    EditAction::make(),
                ])
            ])
            ->toolbarActions([
                CreateAction::make()
                    ->label('Documento Fiscal')
                    ->icon(Heroicon::Plus)
                    ->size(Size::Small),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
