<?php

namespace App\Filament\Clusters\Manufacturing\Resources\ProductionOrders\Tables;

use App\Enum\ProductionOrder\DestinationType;
use App\Enum\ProductionOrder\Priority;
use App\Enum\ProductionOrder\Status;
use App\Filament\Clusters\Manufacturing\Resources\ProductionOrders\Pages\Actions\BulkInvoiceProductionOrderAction;
use App\Filament\Clusters\Manufacturing\Resources\ProductionOrders\Pages\Actions\DownloadProductionOrderPdfAction;
use App\Filament\Clusters\Manufacturing\Resources\ProductionOrders\Pages\Actions\PreviewProductionOrderPdfAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ProductionOrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('production_order_number')
                    ->label('Número')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('customer.name')
                    ->label('Cliente')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->colors([
                        'gray' => Status::QUEUED->value,
                        'info' => Status::IN_PROGRESS->value,
                        'warning' => Status::QC_CHECK->value,
                        'success' => Status::COMPLETED->value,
                        'danger' => Status::CANCELLED->value,
                    ])
                    ->sortable(),
                TextColumn::make('priority')
                    ->label('Prioridade')
                    ->badge()
                    ->colors([
                        'gray' => Priority::LOW->value,
                        'info' => Priority::NORMAL->value,
                        'warning' => Priority::HIGH->value,
                        'danger' => Priority::URGENT->value,
                    ])
                    ->sortable(),
                TextColumn::make('destination_type')
                    ->label('Destino')
                    ->badge()
                    ->colors([
                        'info' => DestinationType::STOCK->value,
                        'success' => DestinationType::DIRECT_DELIVERY->value,
                    ])
                    ->sortable(),
                TextColumn::make('assignedOperator.name')
                    ->label('Operador')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('assigned_machine')
                    ->label('Máquina')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('started_at')
                    ->label('Iniciado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('completed_at')
                    ->label('Concluído em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label('Criado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('createdBy.name')
                    ->label('Criado por')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('customer_id')
                    ->label('Cliente')
                    ->relationship('customer', 'name')
                    ->searchable()
                    ->preload()
                    ->native(false),
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(Status::toSelectArray())
                    ->multiple()
                    ->default([Status::IN_PROGRESS->value, Status::QC_CHECK->value, Status::QUEUED->value])
                    ->native(false),
                SelectFilter::make('priority')
                    ->label('Prioridade')
                    ->options(Priority::toSelectArray())
                    ->native(false),
                SelectFilter::make('destination_type')
                    ->label('Destino')
                    ->options(DestinationType::toSelectArray())
                    ->native(false),
            ])
            ->recordActions([
                PreviewProductionOrderPdfAction::make()
                    ->iconButton(),
                DownloadProductionOrderPdfAction::make()
                    ->iconButton(),
                ViewAction::make()
                    ->iconButton(),
                EditAction::make()
                    ->iconButton(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkInvoiceProductionOrderAction::make(),
                    DeleteBulkAction::make(),
                ]),
                CreateAction::make()
                    ->label('Ordem de Produção')
                    ->icon(Heroicon::Plus)
                    ->size(Size::Small),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
