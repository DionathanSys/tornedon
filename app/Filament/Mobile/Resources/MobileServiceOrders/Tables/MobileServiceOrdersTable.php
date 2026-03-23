<?php

namespace App\Filament\Mobile\Resources\MobileServiceOrders\Tables;

use App\Enum\ServiceOrder\Priority;
use App\Enum\ServiceOrder\State;
use App\Enum\ServiceOrder\Type;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions\BulkInvoiceServiceOrderAction;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions\CancelServiceOrderAction;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions\CloseServiceOrderAction;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions\DownloadServiceOrderPdfAction;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions\DuplicateServiceOrderAction;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions\InvoiceServiceOrderAction;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions\PreviewServiceOrderPdfAction;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions\ReopenServiceOrderAction;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\Schemas\ServiceOrderForm;
use App\Models\ServiceOrder;
use App\Notification\NotifyService as notify;
use App\Services\ServiceOrder\ServiceOrderService;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Layout\Grid;
use Filament\Tables\Columns\Layout\Panel;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class MobileServiceOrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->contentGrid([
                'md' => 2,
                '2xl' => 3,
            ])
            ->columns([
                Split::make([
                    Stack::make([
                        TextColumn::make('number')
                            ->searchable()
                            ->sortable()
                            ->weight('bold'),
                        TextColumn::make('customer.name')
                            ->label('Cliente')
                            ->searchable()
                            ->sortable()
                            ->placeholder('-')
                        // ->lineClamp(2)
                        ,
                        TextColumn::make('equipment.name')
                            ->label('Equipamento')
                            ->searchable()
                            ->sortable()
                            ->placeholder('-')
                            ->color('gray')
                        // ->lineClamp(2)
                        ,
                    ]),
                    Stack::make([
                        Grid::make([
                            'default' => 2,
                            'xl' => 3,
                        ])->schema([
                            TextColumn::make('order_date')
                                ->label('Ordem')
                                ->date('d/m/Y')
                                ->sortable(),
                            TextColumn::make('status')
                                ->label('Status')
                                ->badge()
                                ->formatStateUsing(fn(?State $state): string => $state?->description() ?? '-')
                                ->color(fn(?State $state): string => $state?->color() ?? 'gray')
                                ->sortable(),
                        ]),

                    ])
                        ->alignment(Alignment::End)
                        ->space(1),
                ])->from('md'),

            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(State::toSelectArray())
                    ->default(State::OPEN->value)
                    ->native(false)
                    ->multiple(),
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
                    ->label("T\u{00E9}cnico")
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
                // ActionGroup::make([
                    DuplicateServiceOrderAction::make()
                        ->iconButton(),
                    PreviewServiceOrderPdfAction::make()
                        ->iconButton(),
                    DownloadServiceOrderPdfAction::make()
                        ->iconButton(),
                    CloseServiceOrderAction::make()
                        ->iconButton(),
                    // InvoiceServiceOrderAction::make(),
                    // CancelServiceOrderAction::make(),
                    // ReopenServiceOrderAction::make(),
                    // EditAction::make(),
                // ])
                    // ->label("A\u{00E7}\u{00F5}es")
                    // ->button(),
            ])
            ->recordActionsPosition(RecordActionsPosition::AfterContent)
            ->toolbarActions([
                CreateAction::make()
                    ->label("Ordem de Servi\u{00E7}o")
                    ->icon(Heroicon::Plus)
                    ->size(Size::Small)
                    ->mutateDataUsing(function (array $data): array {
                        $tenant = Filament::getTenant();
                        $data['company_id'] = $tenant->id;
                        $data['status'] = State::OPEN;
                        $data['type'] = Type::MAINTENANCE;
                        $data['priority'] = Priority::NORMAL;

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

                        Log::info("MobileServiceOrdersTable: Ordem de servi\u{00E7}o criada com sucesso", [
                            'metodo' => __METHOD__ . '@' . __LINE__,
                            'service_order_id' => $serviceOrder->id,
                        ]);

                        return $serviceOrder;
                    }),
            ])
            ->searchPlaceholder("Buscar por n\u{00FA}mero, cliente, equipamento, local...");
    }
}
