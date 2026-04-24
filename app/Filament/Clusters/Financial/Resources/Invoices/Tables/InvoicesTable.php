<?php

namespace App\Filament\Clusters\Financial\Resources\Invoices\Tables;

use App\Enum\Invoice\Status;
use App\Filament\Clusters\Financial\Resources\Invoices\Pages\Actions\DownloadInvoicePdfAction;
use App\Filament\Clusters\Financial\Resources\Invoices\Pages\Actions\PreviewInvoicePdfAction;
use App\Filament\Clusters\Financial\Resources\Invoices\Pages\Actions\SendInvoiceEmailAction;
use App\Notification\NotifyService as notify;
use App\Services\Invoice\InvoiceService;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Summarizer;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\ColumnManagerLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Malzariey\FilamentDaterangepickerFilter\Filters\DateRangeFilter;

class InvoicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('invoice_number')
                    ->label('Número')
                    ->searchable()
                    ->sortable()
                    ->icon(Heroicon::Hashtag),
                TextColumn::make('customer.name')
                    ->label('Cliente')
                    ->searchable()
                    ->sortable()
                    ->limit(40),
                TextColumn::make('invoice_date')
                    ->label('Dt. Fatura')
                    ->date('d/m/Y')
                    ->width('1%')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('status')
                    ->label('Status')
                    ->sortable()
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state?->description() ?? '-')
                    ->color(fn ($state) => $state?->color() ?? 'gray')
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('total_amount')
                    ->label('Valor Total')
                    ->money('BRL')
                    ->toggleable(isToggledHiddenByDefault: false)
                    ->summarize(
                        Summarizer::make()
                            ->label('Total')
                            ->money('BRL', 100)
                            ->using(fn (Builder $query): float => self::resolveInvoiceSummaryTotals($query)['total_amount'])
                    ),
                TextColumn::make('discount_amount')
                    ->label('Desconto')
                    ->money('BRL')
                    ->toggleable(isToggledHiddenByDefault: false)
                    ->summarize(
                        Summarizer::make()
                            ->label('Desconto')
                            ->money('BRL', 100)
                            ->using(fn (Builder $query): float => self::resolveInvoiceSummaryTotals($query)['discount_amount'])
                    ),
                TextColumn::make('net_value')
                    ->label('Valor Líquido')
                    ->money('BRL')
                    ->toggleable(isToggledHiddenByDefault: false)
                    ->summarize(
                        Summarizer::make()
                            ->label('Líquido')
                            ->money('BRL', 100)
                            ->using(fn (Builder $query): float => self::resolveInvoiceSummaryTotals($query)['net_value'])
                    ),
                TextColumn::make('createdBy.name')
                    ->label('Criado por')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('confirmed_at')
                    ->label('Confirmado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label('Criado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(Status::toSelectArray())
                    ->multiple()
                    ->native(false),
                DateRangeFilter::make('invoice_date')
                    ->label('Dt. Fatura')
                    ->autoApply()
                    ->firstDayOfWeek(0)
                    ->alwaysShowCalendar()
                    ->defaultLast7Days(),
            ])
            ->recordActions([
                ActionGroup::make([
                    PreviewInvoicePdfAction::make(),
                    DownloadInvoicePdfAction::make(),
                    SendInvoiceEmailAction::make(),
                    EditAction::make(),
                    DeleteAction::make()
                        ->using(function (Model $record): bool {
                            $service = app(InvoiceService::class);
                            $result = $service->delete($record, Auth::id());

                            if ($service->hasError()) {
                                Log::error($service->getMessage(), [
                                    'metodo' => __METHOD__ . '@' . __LINE__,
                                    'message' => $service->getMessage(),
                                    'error_code' => $service->getErrorCode(),
                                    'errors' => $service->getErrors(),
                                    'invoice_id' => $record->id,
                                ]);

                                notify::error(
                                    message: $service->getMessageUser(),
                                    errorCode: $service->getErrorCode()
                                );

                                return false;
                            }

                            return $result;
                        }),
                ])->icon(Heroicon::Bars3),
            ])
            ->toolbarActions([
                // CreateAction::make()
                //     ->label('Fatura')
                //     ->icon(Heroicon::Plus)
                //     ->color('gray')
                //     ->size(Size::Small),
            ])
            ->columnManagerLayout(ColumnManagerLayout::Modal)
            ->columnManagerColumns(2);
    }

    /**
     * Como os totais da fatura são derivados de relacionamentos e não existem como coluna
     * persistida, o resumo precisa agregar via subqueries SQL em vez de Sum::make().
     * O total_amount da OS/requisição já é líquido, então o desconto não pode ser abatido novamente.
     *
     * @return array{total_amount: float, discount_amount: float, net_value: float}
     */
    private static function resolveInvoiceSummaryTotals(Builder $query): array
    {
        $filteredInvoices = DB::query()->fromSub(
            (clone $query)->select('invoices.id'),
            'filtered_invoices'
        );

        $serviceOrderItemsByOrder = DB::table('service_orders as service_orders')
            ->leftJoin('service_order_items as service_order_items', 'service_order_items.service_order_id', '=', 'service_orders.id')
            ->selectRaw('
                service_orders.invoice_id,
                service_orders.id as service_order_id,
                COALESCE(SUM(service_order_items.total_amount), 0) + COALESCE(service_orders.travel_value, 0) as total_amount,
                COALESCE(SUM(service_order_items.discount_amount), 0) as discount_amount
            ')
            ->whereNotNull('service_orders.invoice_id')
            ->groupBy('service_orders.invoice_id', 'service_orders.id', 'service_orders.travel_value');

        $serviceTotalsByInvoice = DB::query()
            ->fromSub($serviceOrderItemsByOrder, 'service_order_totals')
            ->selectRaw('
                service_order_totals.invoice_id,
                COALESCE(SUM(service_order_totals.total_amount), 0) as total_amount,
                COALESCE(SUM(service_order_totals.discount_amount), 0) as discount_amount
            ')
            ->groupBy('service_order_totals.invoice_id');

        $requisitionItemsByRequisition = DB::table('requisitions as requisitions')
            ->leftJoin('requisition_items as requisition_items', 'requisition_items.requisition_id', '=', 'requisitions.id')
            ->selectRaw('
                requisitions.invoice_id,
                requisitions.id as requisition_id,
                COALESCE(SUM(requisition_items.total_amount), 0) as total_amount,
                COALESCE(SUM(requisition_items.discount_amount), 0) as discount_amount
            ')
            ->whereNotNull('requisitions.invoice_id')
            ->groupBy('requisitions.invoice_id', 'requisitions.id');

        $requisitionTotalsByInvoice = DB::query()
            ->fromSub($requisitionItemsByRequisition, 'requisition_totals')
            ->selectRaw('
                requisition_totals.invoice_id,
                COALESCE(SUM(requisition_totals.total_amount), 0) as total_amount,
                COALESCE(SUM(requisition_totals.discount_amount), 0) as discount_amount
            ')
            ->groupBy('requisition_totals.invoice_id');

        $totals = $filteredInvoices
            ->leftJoinSub($serviceTotalsByInvoice, 'service_totals', 'service_totals.invoice_id', '=', 'filtered_invoices.id')
            ->leftJoinSub($requisitionTotalsByInvoice, 'requisition_totals', 'requisition_totals.invoice_id', '=', 'filtered_invoices.id')
            ->selectRaw('
                COALESCE(SUM(COALESCE(service_totals.total_amount, 0) + COALESCE(requisition_totals.total_amount, 0)), 0) as total_amount,
                COALESCE(SUM(COALESCE(service_totals.discount_amount, 0) + COALESCE(requisition_totals.discount_amount, 0)), 0) as discount_amount
            ')
            ->first();

        $totalAmount = round((float) ($totals->total_amount ?? 0), 2);
        $discountAmount = round((float) ($totals->discount_amount ?? 0), 2);

        return [
            'total_amount' => $totalAmount,
            'discount_amount' => $discountAmount,
            'net_value' => $totalAmount,
        ];
    }
}
