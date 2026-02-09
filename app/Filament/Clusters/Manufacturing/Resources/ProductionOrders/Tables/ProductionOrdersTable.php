<?php

namespace App\Filament\Clusters\Manufacturing\Resources\ProductionOrders\Tables;

use App\Enum\ProductionOrder\DestinationType;
use App\Enum\ProductionOrder\Priority;
use App\Enum\ProductionOrder\Status;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
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
                TextColumn::make('partner.name')
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
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(Status::toSelectArray())
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
                ViewAction::make()
                    ->iconButton(),
                EditAction::make()
                    ->iconButton(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
