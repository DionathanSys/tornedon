<?php

namespace App\Filament\Clusters\Financial\Resources\Invoices\Tables;

use App\Enum\Invoice\Status;
use App\Filament\Clusters\Financial\Resources\Invoices\Pages\Actions\DownloadInvoicePdfAction;
use App\Filament\Clusters\Financial\Resources\Invoices\Pages\Actions\PreviewInvoicePdfAction;
use App\Filament\Clusters\Financial\Resources\Invoices\Pages\Actions\SendInvoiceEmailAction;
use App\Models\Invoice;
use App\Notification\NotifyService as notify;
use App\Services\Invoice\InvoiceService;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\ColumnManagerLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
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
                TextColumn::make('gross_amount')
                    ->label('Valor Bruto')
                    ->state(fn (Invoice $record): float => (float) $record->gross_amount)
                    ->formatStateUsing(fn ($state): string => 'R$ ' . number_format((float) ($state ?? 0), 2, ',', '.'))
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('discount_amount')
                    ->label('Desconto')
                    ->state(fn (Invoice $record): float => (float) $record->discount_amount)
                    ->formatStateUsing(fn ($state): string => 'R$ ' . number_format((float) ($state ?? 0), 2, ',', '.'))
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('net_value')
                    ->label('Valor Líquido')
                    ->state(fn (Invoice $record): float => (float) $record->net_value)
                    ->formatStateUsing(fn ($state): string => 'R$ ' . number_format((float) ($state ?? 0), 2, ',', '.'))
                    ->toggleable(isToggledHiddenByDefault: false),
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
}
