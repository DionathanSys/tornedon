<?php

namespace App\Filament\Clusters\Sales\Resources\ServiceOrders\Tables;

use App\Enum\ServiceOrder\Priority;
use App\Enum\ServiceOrder\State;
use App\Filament\Clusters\Financial\Resources\Invoices\InvoiceResource;
use App\Enum\ServiceOrder\Type;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions\BulkInvoiceServiceOrderAction;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions\CancelServiceOrderAction;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions\CloseServiceOrderAction;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions\DownloadServiceOrderPdfAction;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions\DuplicateServiceOrderAction;
use Filament\Facades\Filament;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\Schemas\ServiceOrderForm;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions\InvoiceServiceOrderAction;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions\PreviewServiceOrderPdfAction;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions\ReopenServiceOrderAction;
use App\Services\ServiceOrder\ServiceOrderService;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use App\Notification\NotifyService as notify;

class ServiceOrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')
                    ->label('Número')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('customer.name')
                    ->label('Cliente')
                    ->searchable()
                    ->sortable()
                    ->limit(30),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn($state) => $state->description())
                    ->color(fn($state) => match ($state) {
                        State::OPEN => 'info',
                        State::CLOSED => 'success',
                        State::INVOICED => 'warning',
                        State::CANCELLED => 'danger',
                    })
                    ->sortable(),
                TextColumn::make('priority')
                    ->label('Prioridade')
                    ->badge()
                    ->formatStateUsing(fn($state) => $state->description())
                    ->color(fn($state) => $state->color())
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('type')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn($state) => $state->description())
                    ->color('gray')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('equipment.name')
                    ->label('Equipamento')
                    ->searchable()
                    ->sortable()
                    ->limit(25)
                    ->toggleable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('technician.name')
                    ->label('Técnico')
                    ->searchable()
                    ->sortable()
                    ->limit(25)
                    ->toggleable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('order_date')
                    ->label('Data da Ordem')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('scheduled_date')
                    ->label('Data Agendada')
                    ->date('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('completion_date')
                    ->label('Data Conclusão')
                    ->date('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('invoice.invoice_number')
                    ->label('Fatura')
                    ->sortable()
                    ->placeholder('Sem Fatura')
                    ->toggleable(isToggledHiddenByDefault: false)
                    ->url(fn ($record) => $record->invoice_id ? InvoiceResource::getUrl('edit', ['record' => $record->invoice_id]) : null)
                    ,
                TextColumn::make('created_at')
                    ->label('Criado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label('Atualizado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('priority')
                    ->label('Prioridade')
                    ->options(Priority::toSelectArray())
                    ->native(false)
                    ->multiple(),
                SelectFilter::make('type')
                    ->label('Tipo')
                    ->options(Type::toSelectArray())
                    ->native(false)
                    ->multiple(),
                SelectFilter::make('technician_id')
                    ->label('Técnico')
                    ->relationship('technician', 'name')
                    ->searchable()
                    ->preload()
                    ->native(false),
                SelectFilter::make('customer_id')
                    ->label('Cliente')
                    ->relationship('customer', 'name')
                    ->searchable()
                    ->preload()
                    ->native(false),
            ])
            ->defaultSort('order_date', 'desc')
            ->recordActions([
                ActionGroup::make([
                    DuplicateServiceOrderAction::make(),
                    PreviewServiceOrderPdfAction::make(),
                    DownloadServiceOrderPdfAction::make(),
                    CloseServiceOrderAction::make(),
                    InvoiceServiceOrderAction::make(),
                    CancelServiceOrderAction::make(),
                    ReopenServiceOrderAction::make(),
                    EditAction::make(),
                ])->button()->size(Size::ExtraSmall)->icon(Heroicon::EllipsisVertical),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkInvoiceServiceOrderAction::make(),
                ]),
                CreateAction::make()
                    ->label('Ordem de Serviço')
                    ->icon(Heroicon::Plus)
                    ->size(Size::Small)
                    ->mutateDataUsing(function (array $data): array {
                        $tenant             = Filament::getTenant();
                        $data['company_id'] = $tenant->id;
                        $data['status']     = State::OPEN;
                        $data['priority']   = Priority::NORMAL;
                        $data['type']       = Type::MAINTENANCE;

                        unset($data['discount_amount']);
                        $data['additional_info'] = ServiceOrderForm::normalizeAdditionalInfoState($data['additional_info'] ?? []);

                        if (filled($data['customer_signature'] ?? null)) {
                            $data['customer_signed_at'] = now();
                        } else {
                            $data['customer_signed_at'] = null;
                        }

                        return $data;
                    })
                    ->using(function (array $data, string $model, CreateAction $action): Model {
                        $service = app(ServiceOrderService::class);
                        $serviceOrder = $service->create($data, Auth::id());

                        if ($service->hasError() || $serviceOrder === null) {
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

                        Log::info('CreateServiceOrder: Ordem de serviço criada com sucesso', [
                            'metodo' => __METHOD__ . '@' . __LINE__,
                            'service_order_id' => $serviceOrder->id,
                        ]);

                        return $serviceOrder;
                    }),
            ])
            ->searchPlaceholder('Buscar por número, cliente, equipamento, local...');
    }
}
