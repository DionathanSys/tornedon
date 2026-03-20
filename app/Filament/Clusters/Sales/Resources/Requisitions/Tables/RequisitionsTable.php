<?php

namespace App\Filament\Clusters\Sales\Resources\Requisitions\Tables;

use App\Enum\Requisition\Status;
use App\Filament\Clusters\Sales\Resources\Requisitions\Pages\Actions\BulkInvoiceRequisitionAction;
use App\Filament\Clusters\Sales\Resources\Requisitions\Pages\Actions\DownloadRequisitionPdfAction;
use App\Filament\Clusters\Sales\Resources\Requisitions\Pages\Actions\PreviewRequisitionPdfAction;
use App\Services\Requisition\RequisitionService;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;
use App\Notification\NotifyService as notify;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class RequisitionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')
                    ->label('Número')
                    ->searchable()
                    ->sortable()
                    ->icon(Heroicon::Hashtag),
                TextColumn::make('customer.name')
                    ->label('Cliente')
                    ->searchable()
                    ->sortable()
                    ->limit(40),
                TextColumn::make('sale_date')
                    ->label('Data da Venda')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->sortable()
                    ->badge()
                    ->formatStateUsing(fn($state) => $state?->description() ?? '-')
                    ->color(fn($state) => $state?->color() ?? 'gray'),
                TextColumn::make('salesperson.name')
                    ->label('Vendedor')
                    ->sortable()
                    ->limit(30)
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('equipment.name')
                    ->label('Equipamento')
                    ->sortable()
                    ->limit(30)
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('delivery_date')
                    ->label('Entrega')
                    ->date('d/m/Y')
                    ->sortable()
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('items_count')
                    ->label('Itens')
                    ->counts('items')
                    ->sortable()
                    ->alignEnd()
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label('Criado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
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
                    ->default([Status::OPEN->value])
                    ->native(false),
            ])
            ->recordActions([
                ActionGroup::make([
                    PreviewRequisitionPdfAction::make(),
                    DownloadRequisitionPdfAction::make(),
                    EditAction::make(),
                    DeleteAction::make(),
                ])
                    ->iconButton(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkInvoiceRequisitionAction::make(),
                ]),
                CreateAction::make()
                    ->label('Requisição')
                    ->icon(Heroicon::Plus)
                    ->size(Size::Small)
                    ->using(function (array $data, string $model, CreateAction $action): Model {
                        $service = app(RequisitionService::class);
                        $requisition = $service->create($data, Auth::id());

                        if ($service->hasError() || $requisition === null) {
                            Log::error($service->getMessage(), [
                                'metodo' => __METHOD__ . '@' . __LINE__,
                                'message' => $service->getMessage(),
                                'error_code' => $service->getErrorCode(),
                                'errors' => $service->getErrors(),
                            ]);

                            notify::error(
                                message: $service->getMessageUser(),
                                errorCode: $service->getErrorCode()
                            );

                            $action->halt();
                        }

                        Log::info('CreateRequisition: Requisição criada com sucesso', [
                            'metodo' => __METHOD__ . '@' . __LINE__,
                            'requisition_id' => $requisition->id,
                        ]);

                        return $requisition;
                    }),
            ]);
    }
}
