<?php

namespace App\Filament\Clusters\Financial\Resources\SefazDistributionDocuments\Tables;

use App\Enum\SefazDistributionDocument\ImportStatus;
use App\Enum\SefazDistributionDocument\ManifestationStatus;
use App\Enum\SefazDistributionDocument\Status;
use App\Filament\Clusters\Financial\Resources\FiscalDocuments\FiscalDocumentResource;
use App\Filament\Clusters\Financial\Resources\SefazDistributionDocuments\SefazDistributionDocumentResource;
use App\Jobs\ManifestSefazDistributionDocumentJob;
use App\Jobs\RefreshSefazDistributionDocumentJob;
use App\Models\Partner;
use App\Models\Product;
use App\Models\SefazDistributionDocument;
use App\Services\Fiscal\Sefaz\SefazDistributionFiscalDocumentImportService;
use App\Services\Fiscal\Sefaz\SefazDistributionDocumentService;
use Filament\Facades\Filament;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\FusedGroup;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\ColumnManagerLayout;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;

class SefazDistributionDocumentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('document_number')
                    ->label('Número NF')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('document_series')
                    ->label('Série')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('issuer_name')
                    ->label('Emitente')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('document_key')
                    ->label('Chave')
                    ->tooltip(fn(SefazDistributionDocument $record): string => $record->document_key)
                    ->searchable()
                    ->copyable()
                    ->copyMessage('Chave copiada')
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn(Status $state): string => $state->description())
                    ->color(fn(Status $state): string => $state->color())
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('import_status')
                    ->label('Importação')
                    ->badge()
                    ->formatStateUsing(fn(ImportStatus $state): string => $state->description())
                    ->color(fn(ImportStatus $state): string => $state->color())
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('manifestation_status')
                    ->label('Manifestação')
                    ->badge()
                    ->formatStateUsing(fn(ManifestationStatus $state): string => $state->description())
                    ->color(fn(ManifestationStatus $state): string => $state->color())
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                IconColumn::make('full_xml_available')
                    ->label('XML completo')
                    ->boolean()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('total_amount')
                    ->label('Valor total')
                    ->money('BRL', locale: 'pt_BR')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('issued_at')
                    ->label('Emissão')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('import_ready_at')
                    ->label('Pronto p/ importar')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('fiscalDocument.id')
                    ->label('Nota entrada')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('partner_id')
                    ->label('Fornecedor')
                    ->badge()
                    ->formatStateUsing(fn($state, SefazDistributionDocument $record): string => $record->partner?->name ? 'Vinculado' : 'Pendente')
                    ->color(fn($state, SefazDistributionDocument $record): string => $record->partner?->name ? 'success' : 'warning')
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('last_seen_at')
                    ->label('Última detecção')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('last_action')
                    ->label('Última ação')
                    ->toggleable(isToggledHiddenByDefault: false)
                    ->placeholder('-'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(Status::cases())->mapWithKeys(fn(Status $status) => [
                        $status->value => $status->description(),
                    ])->all()),
                SelectFilter::make('import_status')
                    ->options(collect(ImportStatus::cases())->mapWithKeys(fn(ImportStatus $status) => [
                        $status->value => $status->description(),
                    ])->all()),
                SelectFilter::make('manifestation_status')
                    ->options(collect(ManifestationStatus::cases())->mapWithKeys(fn(ManifestationStatus $status) => [
                        $status->value => $status->description(),
                    ])->all()),
                Filter::make('ready_to_import')
                    ->label('Prontos para importar')
                    ->query(fn($query) => $query->where('import_status', ImportStatus::READY_TO_IMPORT->value)),
                Filter::make('with_errors')
                    ->label('Com erro')
                    ->query(fn($query) => $query->where(function ($subQuery) {
                        $subQuery
                            ->where('status', Status::ERROR->value)
                            ->orWhere('import_status', ImportStatus::IMPORT_ERROR->value);
                    })),
                Filter::make('ignored')
                    ->label('Ignorados')
                    ->query(fn($query) => $query->where('import_status', ImportStatus::IGNORED->value)),
                Filter::make('without_partner')
                    ->label('Sem fornecedor vinculado')
                    ->query(fn($query) => $query->whereNull('partner_id')),
                Filter::make('without_products')
                    ->label('Sem produtos vinculados')
                    ->query(fn($query) => $query->where(function ($subQuery) {
                        $subQuery
                            ->whereNull('items_json')
                            ->orWhereJsonContains('items_json', [['product_id' => null]]);
                    })),
                Filter::make('imported')
                    ->label('Já importados')
                    ->query(fn($query) => $query->where('import_status', ImportStatus::IMPORTED->value)),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('viewTimeline')
                        ->label('Acompanhar')
                        ->icon('heroicon-o-clock')
                        ->url(fn(SefazDistributionDocument $record): string => SefazDistributionDocumentResource::getUrl('view', [
                            'record' => $record,
                            'tenant' => Filament::getTenant(),
                        ])),
                    Action::make('importDocument')
                        ->label('Importar documento')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->color('success')
                        ->requiresConfirmation()
                        ->visible(fn(SefazDistributionDocument $record): bool => $record->full_xml_available
                            && $record->import_status !== ImportStatus::IMPORTED
                            && $record->import_status !== ImportStatus::IGNORED)
                        ->action(function (SefazDistributionDocument $record): void {
                            $fiscalDocument = app(SefazDistributionFiscalDocumentImportService::class)->import($record, Auth::id());

                            Notification::make()
                                ->title('Documento importado')
                                ->body("DF-e importado para a nota de entrada #{$fiscalDocument->id}.")
                                ->success()
                                ->send();
                        }),
                    Action::make('downloadXml')
                        ->label('Baixar XML')
                        ->icon('heroicon-o-document-arrow-down')
                        ->visible(fn(SefazDistributionDocument $record): bool => is_string($record->full_xml_path) || is_string($record->summary_xml_path))
                        ->action(function (SefazDistributionDocument $record) {
                            $path = $record->full_xml_path ?: $record->summary_xml_path;

                            if (! is_string($path) || ! Storage::disk('local')->exists($path)) {
                                Notification::make()
                                    ->title('XML não encontrado')
                                    ->body('O arquivo XML não está disponível no storage.')
                                    ->danger()
                                    ->send();
                                return null;
                            }

                            return response()->download(Storage::disk('local')->path($path), basename($path));
                        }),
                    Action::make('viewXml')
                        ->label('Visualizar XML')
                        ->icon('heroicon-o-code-bracket')
                        ->modalHeading('XML do documento')
                        ->modalSubmitAction(false)
                        ->modalCancelActionLabel('Fechar')
                        ->visible(fn(SefazDistributionDocument $record): bool => is_string($record->full_xml_path) || is_string($record->summary_xml_path))
                        ->modalContent(function (SefazDistributionDocument $record): HtmlString {
                            $path = $record->full_xml_path ?: $record->summary_xml_path;
                            $xml = is_string($path) && Storage::disk('local')->exists($path)
                                ? Storage::disk('local')->get($path)
                                : 'XML não encontrado no storage.';

                            return new HtmlString('<pre style="white-space: pre-wrap; font-size: 12px;">' . e($xml) . '</pre>');
                        }),
                    Action::make('retryManifestation')
                        ->label('Tentar manifestação novamente')
                        ->icon('heroicon-o-arrow-path')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->visible(fn(SefazDistributionDocument $record): bool => ! $record->full_xml_available
                            && in_array($record->manifestation_status, [
                                ManifestationStatus::FAILED,
                                ManifestationStatus::REJECTED,
                            ], true))
                        ->action(function (SefazDistributionDocument $record): void {
                            app(SefazDistributionDocumentService::class)->prepareManualManifestationRetry($record);
                            ManifestSefazDistributionDocumentJob::dispatch($record->id, 1);

                            Notification::make()
                                ->title('Manifestação reenfileirada')
                                ->body('A tentativa manual de manifestação foi enviada para a fila.')
                                ->success()
                                ->send();
                        }),
                    Action::make('retryRefresh')
                        ->label('Reprocessar busca do XML completo')
                        ->icon('heroicon-o-arrow-path-rounded-square')
                        ->color('info')
                        ->requiresConfirmation()
                        ->visible(fn(SefazDistributionDocument $record): bool => ! $record->full_xml_available
                            && $record->nsu !== null
                            && in_array($record->manifestation_status, [
                                ManifestationStatus::ACCEPTED,
                                ManifestationStatus::FAILED,
                                ManifestationStatus::REJECTED,
                            ], true))
                        ->action(function (SefazDistributionDocument $record): void {
                            app(SefazDistributionDocumentService::class)->markManualRefreshRequested($record);
                            RefreshSefazDistributionDocumentJob::dispatch($record->id, 1);

                            Notification::make()
                                ->title('Busca reenfileirada')
                                ->body('A consulta do XML completo foi enviada para a fila.')
                                ->success()
                                ->send();
                        }),
                    Action::make('retryImport')
                        ->label('Reprocessar importação')
                        ->icon('heroicon-o-arrow-path')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->visible(fn(SefazDistributionDocument $record): bool => $record->full_xml_available
                            && $record->import_status === ImportStatus::IMPORT_ERROR)
                        ->action(function (SefazDistributionDocument $record): void {
                            $fiscalDocument = app(SefazDistributionFiscalDocumentImportService::class)->import($record, Auth::id());

                            Notification::make()
                                ->title('Importação reprocessada')
                                ->body("Documento importado para a nota de entrada #{$fiscalDocument->id}.")
                                ->success()
                                ->send();
                        }),
                    Action::make('ignoreDocument')
                        ->label('Ignorar documento')
                        ->icon('heroicon-o-eye-slash')
                        ->color('gray')
                        ->schema([
                            Textarea::make('reason')
                                ->label('Motivo')
                                ->required()
                                ->rows(3),
                        ])
                        ->visible(fn(SefazDistributionDocument $record): bool => $record->import_status !== ImportStatus::IMPORTED
                            && $record->import_status !== ImportStatus::IGNORED)
                        ->action(function (SefazDistributionDocument $record, array $data): void {
                            app(SefazDistributionDocumentService::class)->ignoreDocument($record, (string) $data['reason'], Auth::id());

                            Notification::make()
                                ->title('Documento ignorado')
                                ->success()
                                ->send();
                        }),
                    Action::make('reactivateDocument')
                        ->label('Reativar documento')
                        ->icon('heroicon-o-eye')
                        ->color('success')
                        ->requiresConfirmation()
                        ->visible(fn(SefazDistributionDocument $record): bool => $record->import_status === ImportStatus::IGNORED)
                        ->action(function (SefazDistributionDocument $record): void {
                            app(SefazDistributionDocumentService::class)->reactivateDocument($record, Auth::id());

                            Notification::make()
                                ->title('Documento reativado')
                                ->success()
                                ->send();
                        }),
                    Action::make('linkSupplier')
                        ->label('Vincular fornecedor')
                        ->icon('heroicon-o-user-plus')
                        ->schema(fn(SefazDistributionDocument $record): array => [
                            Select::make('partner_id')
                                ->label('Fornecedor')
                                ->required()
                                ->searchable()
                                ->options(
                                    Partner::query()
                                        ->whereHas('companies', fn($query) => $query->whereKey($record->company_id))
                                        ->orderBy('name')
                                        ->pluck('name', 'id')
                                        ->all()
                                )
                                ->default($record->partner_id),
                        ])
                        ->action(function (SefazDistributionDocument $record, array $data): void {
                            $partner = Partner::query()->findOrFail($data['partner_id']);
                            app(SefazDistributionDocumentService::class)->updatePartnerLink($record, $partner, Auth::id());

                            Notification::make()
                                ->title('Fornecedor vinculado')
                                ->success()
                                ->send();
                        }),
                    Action::make('createSupplier')
                        ->label('Cadastrar fornecedor')
                        ->icon('heroicon-o-user-plus')
                        ->color('success')
                        ->visible(fn(SefazDistributionDocument $record): bool => $record->partner_id === null)
                        ->schema(fn(SefazDistributionDocument $record): array => [
                            Textarea::make('name')
                                ->label('Nome do fornecedor')
                                ->default($record->issuer_name)
                                ->required()
                                ->rows(2),
                            Textarea::make('document_number')
                                ->label('CPF/CNPJ')
                                ->default($record->issuer_document)
                                ->required()
                                ->rows(1),
                        ])
                        ->action(function (SefazDistributionDocument $record, array $data): void {
                            app(SefazDistributionDocumentService::class)->createAndLinkSupplier(
                                $record,
                                (string) $data['name'],
                                (string) $data['document_number'],
                                Auth::id(),
                            );

                            Notification::make()
                                ->title('Fornecedor cadastrado e vinculado')
                                ->success()
                                ->send();
                        }),
                    Action::make('linkItems')
                        ->label('Vincular itens a produtos')
                        ->icon('heroicon-o-link')
                        ->modalWidth('5xl')
                        ->visible(fn(SefazDistributionDocument $record): bool => $record->full_xml_available && ! empty($record->items_json))
                        ->schema(fn(SefazDistributionDocument $record): array => [
                            Repeater::make('items')
                                ->label('Itens')
                                ->columnSpanFull()
                                ->columns(12)
                                ->default(
                                    collect($record->items_json ?? [])->map(function (array $item): array {
                                        return [
                                            'line' => $item['line'] ?? null,
                                            'product_code' => $item['product_code'] ?? null,
                                            'description' => $item['description'] ?? null,
                                            'quantity' => $item['quantity'] ?? null,
                                            'product_id' => $item['product_id'] ?? null,
                                            'product_name' => $item['product_name'] ?? null,
                                        ];
                                    })->all()
                                )
                                ->schema([
                                    FusedGroup::make([
                                        TextInput::make('product_code')
                                            ->disabled()
                                            ->state(fn($state) => "Cód. " . $state)
                                            ->columnSpan(2),
                                        TextInput::make('description')
                                            ->disabled()
                                            ->columnSpan(8),
                                        TextInput::make('quantity')
                                            ->disabled()
                                            ->state(fn($state) => 'Qtd. ' . $state)
                                            ->columnSpan(2)
                                    ])
                                        ->label('Informações do item')
                                        ->columnSpanFull()
                                        ->columns(12),
                                    Select::make('product_id')
                                        ->label('Produto interno')
                                        ->searchable()
                                        ->columnSpanFull()
                                        ->options(
                                            Product::query()
                                                ->where('company_id', $record->company_id)
                                                ->orderBy('name')
                                                ->get()
                                                ->mapWithKeys(fn(Product $product): array => [
                                                    $product->id => trim(($product->product_code ? "[{$product->product_code}] " : '') . $product->name),
                                                ])
                                                ->all()
                                        ),
                                ])
                                ->columns(4),
                        ])
                        ->action(function (SefazDistributionDocument $record, array $data): void {
                            $currentItems = collect($record->items_json ?? []);
                            $mappedItems = collect($data['items'] ?? []);

                            $updatedItems = $currentItems->map(function (array $item, int $index) use ($mappedItems): array {
                                $mapping = $mappedItems->get($index, []);
                                $productId = $mapping['product_id'] ?? null;
                                $product = $productId ? Product::query()->find($productId) : null;
                                $item['product_id'] = $product?->id;
                                $item['product_name'] = $product?->name;

                                return $item;
                            })->all();

                            app(SefazDistributionDocumentService::class)->updateItemMappings($record, $updatedItems, Auth::id());
                            $notification = Notification::make()
                                ->title('Itens vinculados');

                            if ($record->partner_id === null) {
                                $notification
                                    ->warning()
                                    ->body('Os itens foram vinculados neste DF-e, mas o pré-vínculo automático só será salvo após vincular o fornecedor.');
                            } else {
                                $notification
                                    ->success()
                                    ->body('Os vínculos foram salvos e serão reaproveitados nas próximas notas deste fornecedor.');
                            }

                            $notification->send();
                        }),
                    Action::make('openFiscalDocument')
                        ->label('Visualizar nota de entrada')
                        ->icon('heroicon-o-arrow-top-right-on-square')
                        ->color('primary')
                        ->visible(fn(SefazDistributionDocument $record): bool => $record->fiscal_document_id !== null)
                        ->url(fn(SefazDistributionDocument $record): string => FiscalDocumentResource::getUrl('edit', [
                            'record' => $record->fiscal_document_id,
                            'tenant' => Filament::getTenant(),
                        ])),
                ])->icon(Heroicon::Bars3)
            ], RecordActionsPosition::BeforeCells)
            ->toolbarActions([
                DeleteBulkAction::make()
                    ->label('Excluir notas'),
            ])
            ->defaultSort('last_seen_at', 'desc')
            ->reorderableColumns()
            ->persistFiltersInSession()
            ->persistSearchInSession()
            ->columnManagerLayout(ColumnManagerLayout::Modal)
            ->columnManagerColumns(2);
    }
}
