<?php

namespace App\Filament\Clusters\Sales\Resources\ServiceOrders\Tables;

use App\Enum\ServiceOrder\Priority;
use App\Enum\ServiceOrder\State;
use App\Enum\ServiceOrder\Type;
use App\Filament\Clusters\Financial\Resources\Invoices\InvoiceResource;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions\BulkInvoiceServiceOrderAction;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions\CancelServiceOrderAction;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions\CloseServiceOrderAction;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions\CreateServiceOrderAction;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions\DownloadServiceOrderPdfAction;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions\DuplicateServiceOrderAction;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions\InvoiceServiceOrderAction;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions\PreviewServiceOrderPdfAction;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions\ReopenServiceOrderAction;
use App\Services\Equipment\EquipmentService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ServiceOrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
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
                    ->formatStateUsing(fn ($state) => $state->description())
                    ->color(fn ($state) => $state->color())
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('type')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state->description())
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
                TextColumn::make('total_amount')
                    ->label('Total')
                    ->money('BRL', 100),
                TextColumn::make('discount_amount')
                    ->label('Desc. (R$)')
                    ->money('BRL', 100),
                TextColumn::make('order_date')
                    ->label('Dt. Ordem')
                    ->date('d/m/Y')
                    ->width('1%')
                    ->sortable(),
                TextColumn::make('scheduled_date')
                    ->label('Dt. Agendada')
                    ->date('d/m/Y')
                    ->width('1%')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
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
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->url(fn ($record) => $record->invoice_id ? InvoiceResource::getUrl('edit', ['record' => $record->invoice_id]) : null),
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
                    ->relationship('customer', 'name')
                    ->searchable()
                    ->preload()
                    ->native(false),
                SelectFilter::make('equipment_id')
                    ->label('Equipamento')
                    ->searchable()
                    ->preload()
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
            ])
            ->defaultSort('order_date', 'desc')
            ->recordActions([
                ActionGroup::make([
                    DuplicateServiceOrderAction::make(),
                    PreviewServiceOrderPdfAction::make(),
                    DownloadServiceOrderPdfAction::make(),
                    CloseServiceOrderAction::make(),
                    InvoiceServiceOrderAction::make(),
                    Action::make('open-invoice')
                        ->url(fn ($record) => InvoiceResource::getUrl('edit', ['record' => $record->invoice_id]))
                        ->visible(fn ($record) => $record->invoice_id)
                        ->icon(Heroicon::Eye)
                        ->label('Ver Fatura'),
                    CancelServiceOrderAction::make(),
                    ReopenServiceOrderAction::make(),
                    EditAction::make(),
                ])->size(Size::ExtraSmall)->icon(Heroicon::Bars3),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkInvoiceServiceOrderAction::make(),
                ]),
                CreateServiceOrderAction::make()
                    ->label('Ordem Serviço'),
            ])
            ->searchPlaceholder('Buscar por número, cliente, equipamento, local...');
    }
}
