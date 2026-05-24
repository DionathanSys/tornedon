<?php

namespace App\Filament\Clusters\Sales\Resources\ServiceOrders\RelationManagers;

use App\Filament\Clusters\Financial\Resources\FiscalDocuments\FiscalDocumentResource as FinancialFiscalDocumentResource;
use App\Filament\Clusters\Sales\Resources\FiscalDocuments\FiscalDocumentResource as SalesFiscalDocumentResource;
use App\Models\RemittanceAsset;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ReceivedAssetsRelationManager extends RelationManager
{
    protected static string $relationship = 'remittanceAssets';

    protected static ?string $title = 'Remessas Vinculadas';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Ativos recebidos pela nota de remessa')
            ->columns([
                TextColumn::make('fiscalDocument.document_number')
                    ->label('NF Remessa')
                    ->placeholder('-')
                    ->url(fn (RemittanceAsset $record): ?string => $record->fiscalDocument
                        ? FinancialFiscalDocumentResource::getUrl('edit', ['record' => $record->fiscalDocument])
                        : null, true),
                TextColumn::make('fiscalDocumentItem.item_number')
                    ->label('Item')
                    ->placeholder('-'),
                TextColumn::make('equipment.name')
                    ->label('Equipamento')
                    ->searchable()
                    ->placeholder('-')
                    ->limit(30),
                TextColumn::make('serial_number')
                    ->label('Serie')
                    ->placeholder('-'),
                TextColumn::make('lot_number')
                    ->label('Lote')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('received_quantity')
                    ->label('Recebido')
                    ->numeric(4, ',', '.'),
                TextColumn::make('pivot.quantity_allocated')
                    ->label('Alocado OS')
                    ->numeric(4, ',', '.')
                    ->placeholder('-'),
                TextColumn::make('returned_quantity')
                    ->label('Retornado')
                    ->numeric(4, ',', '.'),
                TextColumn::make('pending_quantity')
                    ->label('Pendente')
                    ->state(fn (RemittanceAsset $record): float => $record->pending_quantity)
                    ->numeric(4, ',', '.'),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'returned' => 'Retornado',
                        'received' => 'Recebido',
                        default => ucfirst($state),
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'returned' => 'success',
                        'received' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('linked_return_document')
                    ->label('NF Retorno')
                    ->state(fn (RemittanceAsset $record): ?string => $record->linkedReturnFiscalDocument()?->document_number)
                    ->placeholder('-')
                    ->url(fn (RemittanceAsset $record): ?string => ($linkedRecord = $record->linkedReturnFiscalDocument())
                        ? SalesFiscalDocumentResource::getUrl('edit', ['record' => $linkedRecord])
                        : null, true),
            ])
            ->defaultSort('remittance_assets.id')
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([])
            ->emptyStateDescription('Esta ordem de serviço ainda não possui ativos recebidos vinculados.');
    }
}
