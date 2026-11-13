<?php

namespace App\Filament\Clusters\Financial\Resources\FiscalDocuments\Tables;

use App\Enum\FiscalDocument\DocumentModel;
use App\Enum\FiscalDocument\Status;
use App\Filament\Clusters\Financial\Resources\FiscalDocuments\Actions\CreatePurchaseClosingBulkAction;
use App\Filament\Clusters\Financial\Resources\FiscalDocuments\Actions\GeneratePurchaseReturnAction;
use App\Filament\Clusters\Financial\Resources\FiscalDocuments\FiscalDocumentResource;
use App\Filament\Clusters\Sales\Resources\FiscalDocuments\FiscalDocumentResource as SalesFiscalDocumentResource;
use App\Models\FiscalDocument;
use App\Notification\NotifyService as notify;
use App\Services\FiscalDocument\PurchaseReturnBalanceService;
use App\Services\FiscalDocument\PurchaseReturnFiscalDocumentService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Malzariey\FilamentDaterangepickerFilter\Filters\DateRangeFilter;

class FiscalDocumentsTable
{
    public static function configure(Table $table): Table
    {
        // Build aggregates once: Filament also resolves this query while rendering each action.
        $query = FiscalDocumentResource::getEloquentQuery()->select([
            'id', 'company_id', 'created_by', 'customer_id', 'document_number', 'document_series',
            'document_type', 'operation_type', 'status', 'canceled', 'issued_at', 'movement_at',
            'document_key', 'confirmed', 'confirmed_at', 'created_at',
        ])->without(['taxDetail', 'payload'])->with('customer:id,name')
            ->withSum('items as items_total', 'total_price');

        return $table
            ->query(app(PurchaseReturnBalanceService::class)->withAvailableBalance($query))
            ->columns([
                TextColumn::make('document_number')
                    ->label('Número NF')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('document_series')
                    ->label('Série')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('customer.name')
                    ->label('Fornecedor')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (Status $state): string => $state->description())
                    ->color(fn (Status $state): string => $state->color())
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('issued_at')
                    ->label('Data Emissão')
                    ->date('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('movement_at')
                    ->label('Data Entrada')
                    ->date('d/m/Y')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('items_total')
                    ->label('Valor Total')
                    ->money('BRL')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('document_key')
                    ->label('Chave')
                    ->searchable()
                    ->limit(20)
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('confirmed')
                    ->label('Confirmada')
                    ->boolean()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('confirmed_at')
                    ->label('Confirmado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('createdBy.name')
                    ->label('Criado por')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label('Criado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('customer_id')
                    ->label('Parceiro')
                    ->relationship('customer', 'name')
                    ->searchable()
                    ->preload()
                    ->native(false),
                SelectFilter::make('document_type')
                    ->label('Tipo de Documento')
                    ->options(DocumentModel::toSelectArray()),
                DateRangeFilter::make('issued_at')
                    ->label('Data de Emissão')
                    ->autoApply()
                    ->firstDayOfWeek(0)
                    ->alwaysShowCalendar(),
                DateRangeFilter::make('movement_at')
                    ->label('Data de Entrada')
                    ->autoApply()
                    ->firstDayOfWeek(0)
                    ->alwaysShowCalendar(),
                Filter::make('confirmed')
                    ->label('Confirmado')
                    ->toggle(),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make()->model(FiscalDocument::class)->authorize(fn ($record, $livewire): bool => $livewire->canUseFiscalDocumentAction($record, 'update')),
                    Action::make('generatePurchaseReturn')
                        ->model(FiscalDocument::class)
                        ->label('Gerar nota de devolução')
                        ->icon('heroicon-o-arrow-uturn-left')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->visible(fn ($record): bool => GeneratePurchaseReturnAction::isVisible($record))
                        ->action(function ($record): void {
                            $service = app(PurchaseReturnFiscalDocumentService::class);
                            $returnDocument = $service->generateFromEntry($record, Auth::id());

                            if ($service->hasError() || $returnDocument === null) {
                                notify::error(message: $service->getMessageUser());

                                return;
                            }

                            notify::success('Nota de devolução gerada com sucesso.');

                            redirect(SalesFiscalDocumentResource::getUrl('edit', ['record' => $returnDocument]));
                        }),
                ])->icon(Heroicon::Bars3),

            ], RecordActionsPosition::BeforeCells)
            ->recordUrl(fn ($record, $livewire): ?string => $livewire->canUseFiscalDocumentAction($record, 'update')
                ? FiscalDocumentResource::getUrl('edit', ['record' => $record]) : null)
            ->toolbarActions([
                BulkActionGroup::make([
                    CreatePurchaseClosingBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
