<?php

namespace App\Filament\Clusters\Inventory\Resources\StockMovements\Tables;

use App\Enum\StockMovement\Type;
use App\Filament\Clusters\Financial\Resources\FiscalDocuments\FiscalDocumentResource as FinancialFiscalDocumentResource;
use App\Filament\Clusters\Manufacturing\Resources\ProductionOrders\ProductionOrderResource;
use App\Filament\Clusters\Sales\Resources\Quotes\QuoteResource;
use App\Filament\Clusters\Sales\Resources\Requisitions\RequisitionResource;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\ServiceOrderResource;
use App\Filament\Clusters\Inventory\Resources\StockMovements\Actions\Bulk\CheckProductStockBulkAction;
use App\Filament\Clusters\Inventory\Resources\StockMovements\Actions\Bulk\FixProductStockBulkAction;
use App\Filament\Clusters\Inventory\Resources\StockMovements\Actions\CreateStockMovementFromModalAction;
use App\Models\FiscalDocument;
use App\Models\RequisitionItem;
use App\Models\StockMovement;
use Filament\Actions\BulkActionGroup;
use Filament\Facades\Filament;
use Filament\Support\Enums\Size;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\ColumnManagerLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Malzariey\FilamentDaterangepickerFilter\Filters\DateRangeFilter;

class StockMovementsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn(): Builder => StockMovement::query()
                ->where('company_id', Filament::getTenant()->id))
            ->recordUrl(fn(StockMovement $record): ?string => static::resolveRecordUrl($record))
            ->openRecordUrlInNewTab()
            ->columns([
                TextColumn::make('created_at')
                    ->label('Data')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('product.code')
                    ->label('Código')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('product.name')
                    ->label('Produto')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('type')
                    ->label('Tipo de Mov.')
                    ->formatStateUsing(fn($state) => $state->label())
                    ->color(fn($state) => $state->color())
                    ->badge()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('operational_quantity')
                    ->label('Qtde. Operação')
                    ->numeric(3, ',', '.')
                    ->formatStateUsing(fn($state, StockMovement $record) => number_format($state ?? $record->quantity, 3, ',', '.') . ' ' . ($record->operational_unit ?? $record->base_unit ?? 'UN'))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('base_quantity')
                    ->label('Qtde. Estoque')
                    ->numeric(3, ',', '.')
                    ->formatStateUsing(fn($state, StockMovement $record) => number_format($state ?? $record->quantity, 3, ',', '.') . ' ' . ($record->base_unit ?? 'UN'))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('quantity')
                    ->label('Qtde. (Deprecated)')
                    ->numeric(3, ',', '.')
                    ->formatStateUsing(fn($state, StockMovement $record) => number_format($state ?? $record->quantity, 3, ',', '.') . ' ' . ($record->base_unit ?? 'UN'))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('unit_price')
                    ->label('Custo Un.')
                    ->money('BRL')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('total_amount')
                    ->label('Custo Total')
                    ->money('BRL')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('reason')
                    ->label('Motivo')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('reference_type')
                    ->label('Referência')
                    ->formatStateUsing(fn($state) => $state ? ucfirst($state) : '-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('source_type')
                    ->label('Origem')
                    ->formatStateUsing(fn($state) => $state ? ucfirst($state) : '-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('observations')
                    ->label('Observações')
                    ->limit(50)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('createdBy.name')
                    ->label('Criado por')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label('Tipo de Mov.')
                    ->options(collect(Type::cases())->mapWithKeys(fn($type) => [$type->value => $type->label()])->toArray()),
                DateRangeFilter::make('created_at')
                    ->label('Período de Movimentação'),
                SelectFilter::make('product_id')
                    ->label('Produto')
                    ->relationship('product', 'name')
                    ->searchable()
                    ->preload(),

            ])
            ->groups([
                Group::make('product.name')
                    ->label('Produto')
                    ->collapsible(),
            ])
            ->toolbarActions([
                CreateStockMovementFromModalAction::make()
                    ->color('gray')
                    ->size(Size::Small),
                BulkActionGroup::make([
                    CheckProductStockBulkAction::make(),
                    FixProductStockBulkAction::make(),
                ])->label('Estoque'),
            ])
            ->defaultSort('created_at', 'desc')
            ->reorderableColumns()
            ->persistSortInSession()
            ->persistFiltersInSession()
            ->persistSearchInSession()
            ->columnManagerColumns(4)
            ->columnManagerLayout(ColumnManagerLayout::Modal);
    }

    private static function resolveRecordUrl(StockMovement $record): ?string
    {
        return match ($record->source_type) {
            'requisition' => RequisitionResource::getUrl('edit', ['record' => $record->source_id]),
            'requisition_item' => static::resolveRequisitionItemUrl($record->source_id),
            'quote' => QuoteResource::getUrl('edit', ['record' => $record->source_id]),
            'service_order' => ServiceOrderResource::getUrl('edit', ['record' => $record->source_id]),
            'production_order' => ProductionOrderResource::getUrl('edit', ['record' => $record->source_id]),
            'fiscal_document' => static::resolveFiscalDocumentUrl($record->source_id),
            default => null,
        };
    }

    private static function resolveRequisitionItemUrl(int|string|null $sourceId): ?string
    {
        $requisitionId = RequisitionItem::query()
            ->whereKey($sourceId)
            ->value('requisition_id');

        if (blank($requisitionId)) {
            return null;
        }

        return RequisitionResource::getUrl('edit', ['record' => $requisitionId]);
    }

    private static function resolveFiscalDocumentUrl(int|string|null $sourceId): ?string
    {
        $document = FiscalDocument::query()
            ->select(['id'])
            ->whereKey($sourceId)
            ->where('company_id', Filament::getTenant()->id)
            ->first();

        if (! $document) {
            return null;
        }

        return FinancialFiscalDocumentResource::getUrl('edit', ['record' => $document]);
    }
}
