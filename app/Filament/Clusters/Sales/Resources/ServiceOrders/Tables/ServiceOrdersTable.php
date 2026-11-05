<?php

namespace App\Filament\Clusters\Sales\Resources\ServiceOrders\Tables;

use App\Enum\ServiceOrder\Priority;
use App\Enum\ServiceOrder\State;
use App\Enum\ServiceOrder\Type;
use App\Filament\Clusters\Financial\Resources\Invoices\InvoiceResource;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions\BulkInvoiceServiceOrderAction;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions\CancelServiceOrderAction;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions\CloseSelectedServiceOrdersAction;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions\CloseServiceOrderAction;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions\CreateServiceOrderAction;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions\DownloadServiceOrderPdfAction;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions\DuplicateServiceOrderAction;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions\ExportSelectedDetailedServiceOrdersPdfAction;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions\ExportSelectedServiceOrdersAction;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions\ExportSelectedServiceOrdersPdfAction;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions\GenerateServiceOrderSignatureLinkAction;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions\InvoiceServiceOrderAction;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions\PreviewServiceOrderPdfAction;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions\ReopenServiceOrderAction;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions\TransferServiceOrderAction;
use App\Models\ServiceOrder;
use App\Notification\NotifyService as notify;
use App\Services\Equipment\EquipmentService;
use App\Services\Partner\PartnerService;
use App\Services\ServiceOrder\ServiceOrderService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Support\Enums\Size;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\ColumnManagerLayout;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Indicator;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class ServiceOrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['items', 'requisition.items']))
            ->columns([
                TextColumn::make('number')
                    ->label('Número')
                    ->searchable()
                    ->width('1%')
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('customer.name')
                    ->label('Cliente')
                    ->searchable()
                    ->sortable()
                    ->limit(35),
                TextColumn::make('order_date')
                    ->label('Dt. Ordem')
                    ->date('d/m/Y')
                    ->width('1%')
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->width('1%')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state->description())
                    ->color(fn ($state) => match ($state) {
                        State::OPEN => 'info',
                        State::CLOSED => 'success',
                        State::INVOICED => 'warning',
                        State::CANCELLED => 'danger',
                    })
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('priority')
                    ->label('Prioridade')
                    ->badge()
                    ->width('1%')
                    ->formatStateUsing(fn ($state) => $state->description())
                    ->color(fn ($state) => $state->color())
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('type')
                    ->label('Tipo')
                    ->badge()
                    ->width('1%')
                    ->formatStateUsing(fn ($state) => $state->description())
                    ->color('gray')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('equipment.name')
                    ->label('Equipamento')
                    ->description(fn (ServiceOrder $record): ?string => $record->equipment?->identifier)
                    ->searchable(['name', 'placa', 'serial_number'])
                    ->placeholder('Sem equipamento')
                    ->sortable()
                    ->limit(25)
                    ->toggleable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('technician.name')
                    ->label('Técnico')
                    ->searchable()
                    ->placeholder('Sem técnico')
                    ->sortable()
                    ->width('1%')
                    ->limit(25)
                    ->toggleable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('services_total_amount')
                    ->label('Total Serviços')
                    ->state(fn (ServiceOrder $record): float => (float) $record->services_total_amount)
                    ->formatStateUsing(fn ($state): string => 'R$ '.number_format((float) ($state ?? 0), 2, ',', '.'))
                    ->width('1%')
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('requisition_total_amount')
                    ->label('Total Produtos')
                    ->state(fn (ServiceOrder $record): float => (float) $record->requisition_total_amount)
                    ->formatStateUsing(fn ($state): string => 'R$ '.number_format((float) ($state ?? 0), 2, ',', '.'))
                    ->width('1%')
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('grand_total_amount')
                    ->label('Total Geral')
                    ->state(fn (ServiceOrder $record): float => (float) $record->grand_total_amount)
                    ->formatStateUsing(fn ($state): string => 'R$ '.number_format((float) ($state ?? 0), 2, ',', '.'))
                    ->width('1%')
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('scheduled_date')
                    ->label('Dt. Agendada')
                    ->date('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('completion_date')
                    ->label('Dt. Conclusão')
                    ->date('d/m/Y')
                    ->width('1%')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('invoice.invoice_number')
                    ->label('Fatura')
                    ->sortable()
                    ->placeholder('Sem Fatura')
                    ->url(fn ($record) => $record->invoice_id ? InvoiceResource::getUrl('edit', ['record' => $record->invoice_id]) : null),
                TextColumn::make('createdBy.name')
                    ->label('Criado por')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updatedBy.name')
                    ->label('Atualizado por')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
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
                SelectFilter::make('customer_id')
                    ->label('Cliente')
                    ->searchable()
                    ->getSearchResultsUsing(
                        fn (string $search): array => (new PartnerService)
                            ->searchForSelect($search, Filament::getTenant()->id, 'customer', 20, ['document_number' => false])
                    )
                    ->getOptionLabelUsing(
                        fn ($value): ?string => (new PartnerService)
                            ->getLabelForSelect((int) $value, ['document_number' => false])
                    )
                    ->native(false),
                SelectFilter::make('equipment_id')
                    ->label('Equipamento')
                    ->searchable()
                    ->getSearchResultsUsing(
                        fn (string $search): array => (new EquipmentService)
                            ->searchForSelect($search, Filament::getTenant()->id, null, 20, ['owner' => false])
                    )
                    ->getOptionLabelUsing(
                        fn ($value): ?string => (new EquipmentService)
                            ->getLabelForSelect((int) $value)
                    )
                    ->native(false),
                SelectFilter::make('priority')
                    ->label('Prioridade')
                    ->options(Priority::toSelectArray())
                    ->native(false)
                    ->multiple(),
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(State::toSelectArray())
                    ->native(false)
                    ->multiple(),
                Filter::make('order_date')
                    ->label('Data da Ordem')
                    ->schema([
                        DatePicker::make('from')->label('Data da ordem: de'),
                        DatePicker::make('until')->label('Data da ordem: até')->afterOrEqual('from'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, string $date): Builder => $query->where('order_date', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, string $date): Builder => $query->where('order_date', '<', Carbon::parse($date)->addDay()->startOfDay())))
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];

                        if ($data['from'] ?? null) {
                            $indicators[] = Indicator::make('Data da ordem: de '.Carbon::parse($data['from'])->format('d/m/Y'))->removeField('from');
                        }

                        if ($data['until'] ?? null) {
                            $indicators[] = Indicator::make('Data da ordem: até '.Carbon::parse($data['until'])->format('d/m/Y'))->removeField('until');
                        }

                        return $indicators;
                    }),
                SelectFilter::make('type')
                    ->label('Tipo')
                    ->options(Type::toSelectArray())
                    ->native(false)
                    ->multiple(),
            ], layout: FiltersLayout::Modal)
            ->filtersFormColumns(['default' => 1, 'md' => 2])
            ->filtersFormWidth(Width::ThreeExtraLarge)
            ->filtersTriggerAction(fn (Action $action): Action => $action->stickyModalHeader()->stickyModalFooter())
            ->persistFiltersInSession()
            ->defaultSort('order_date', 'desc')
            ->recordActions([
                ActionGroup::make([
                    DuplicateServiceOrderAction::make(),
                    PreviewServiceOrderPdfAction::make(),
                    DownloadServiceOrderPdfAction::make(),
                    GenerateServiceOrderSignatureLinkAction::make(),
                    CloseServiceOrderAction::make(),
                    TransferServiceOrderAction::make(),
                    InvoiceServiceOrderAction::make(),
                    Action::make('open-invoice')
                        ->url(fn ($record) => InvoiceResource::getUrl('edit', ['record' => $record->invoice_id]))
                        ->visible(fn ($record) => $record->invoice_id)
                        ->icon(Heroicon::Eye)
                        ->label('Acessar Fatura'),
                    CancelServiceOrderAction::make(),
                    DeleteAction::make()
                        ->hiddenLabel()
                        ->icon(Heroicon::Trash)
                        ->visible(fn (Model $record): bool => blank($record->invoice_id) && $record->requisition === null)
                        ->using(function (Model $record): bool {
                            Log::debug('EditServiceOrder: Iniciando exclusão de ordem de serviço', [
                                'metodo' => __METHOD__.'@'.__LINE__,
                                'service_order_id' => $record->id,
                            ]);

                            $service = app(ServiceOrderService::class);
                            $result = $service->delete($record);

                            if ($service->hasError()) {
                                Log::error('EditServiceOrder: Erro ao deletar ordem de serviço', [
                                    'metodo' => __METHOD__.'@'.__LINE__,
                                    'error_code' => $service->getErrorCode(),
                                    'message' => $service->getMessage(),
                                    'service_order_id' => $record->id,
                                ]);

                                notify::error(
                                    message: $service->getMessageUser(),
                                    errorCode: $service->getErrorCode()
                                );

                                return false;
                            }

                            Log::info('EditServiceOrder: Ordem de serviço deletada com sucesso', [
                                'metodo' => __METHOD__.'@'.__LINE__,
                                'service_order_id' => $record->id,
                            ]);

                            return $result;
                        }),
                    ReopenServiceOrderAction::make(),
                    EditAction::make(),
                ])->size(Size::ExtraSmall)->icon(Heroicon::Bars3),
            ], RecordActionsPosition::BeforeCells)
            ->toolbarActions([
                BulkActionGroup::make([
                    CloseSelectedServiceOrdersAction::make(),
                    BulkInvoiceServiceOrderAction::make(),
                    ExportSelectedServiceOrdersAction::make(),
                    ExportSelectedServiceOrdersPdfAction::make(),
                    ExportSelectedDetailedServiceOrdersPdfAction::make(),
                ]),
                CreateServiceOrderAction::make()
                    ->label('Ordem Serviço')
                    ->hiddenLabel(false)
                    ->color('gray'),
            ])
            ->reorderableColumns()
            ->persistFiltersInSession()
            ->persistSearchInSession()
            ->columnManagerLayout(ColumnManagerLayout::Modal)
            ->columnManagerColumns(2)
            ->searchPlaceholder('Buscar por número, cliente, equipamento, local...');
    }
}
