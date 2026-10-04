<?php

namespace App\Filament\Clusters\Financial\Resources\PixCharges;

use App\Enum\Financial\PixChargeStatus;
use App\Filament\Clusters\Financial\Resources\PixCharges\Pages\ListPixCharges;
use App\Filament\Support\Actions\PixPaymentLinkAction;
use App\Jobs\QueryPixChargeJob;
use App\Jobs\RegisterPixChargeJob;
use App\Models\PixCharge;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use UnitEnum;

class PixChargeResource extends Resource
{
    protected static ?string $model = PixCharge::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::QrCode;

    protected static string|UnitEnum|null $navigationGroup = 'Financeiro';

    protected static ?string $modelLabel = 'Cobrança PIX';

    protected static ?string $pluralModelLabel = 'Cobranças PIX';

    protected static ?int $navigationSort = 5;

    public static function form(Schema $schema): Schema
    {
        return $schema;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('installment.accountReceivable.invoice.invoice_number')
                    ->label('Fatura')
                    ->searchable()
                    ->sortable()
                    ->placeholder('-'),
                TextColumn::make('installment.sequence_number')
                    ->label('Parcela')
                    ->badge()
                    ->sortable(),
                TextColumn::make('installment.accountReceivable.customer.name')
                    ->label('Pagador')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('amount')
                    ->label('Valor')
                    ->money('BRL')
                    ->sortable(),
                TextColumn::make('due_date')
                    ->label('Vencimento')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (?PixChargeStatus $state): string => $state?->description() ?? '-')
                    ->color(fn (?PixChargeStatus $state): string => $state?->color() ?? 'gray')
                    ->sortable(),
                TextColumn::make('pix_copy_paste')
                    ->label('Copia e cola')
                    ->copyable()
                    ->copyMessage('Código PIX copiado')
                    ->limit(50)
                    ->tooltip(fn (?string $state): ?string => $state)
                    ->placeholder('-'),
                TextColumn::make('qr_code')
                    ->label('QR Code')
                    ->formatStateUsing(fn (?string $state): string => filled($state) ? 'Disponível' : '-')
                    ->badge()
                    ->color(fn (?string $state): string => filled($state) ? 'success' : 'gray')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('provider_status_message')
                    ->label('Mensagem provider')
                    ->limit(60)
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('last_error')
                    ->label('Erro')
                    ->color('danger')
                    ->limit(80)
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('paid_at')
                    ->label('Pago em')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('-')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(collect(PixChargeStatus::cases())
                        ->mapWithKeys(fn (PixChargeStatus $status): array => [$status->value => $status->description()])
                        ->all())
                    ->multiple(),
            ])
            ->recordActions(ActionGroup::make([
                PixPaymentLinkAction::make(),
                PixPaymentLinkAction::open(),
                Action::make('query_provider')
                    ->label('Consultar no provider')
                    ->icon(Heroicon::ArrowPath)
                    ->color('info')
                    ->visible(fn (PixCharge $record): bool => filled($record->provider_identification)
                        && $record->status === PixChargeStatus::REGISTERED)
                    ->action(function (PixCharge $record): void {
                        QueryPixChargeJob::dispatch($record->id);

                        Notification::make()
                            ->title('Consulta da cobrança PIX enfileirada.')
                            ->success()
                            ->send();
                    }),
                Action::make('retry_registration')
                    ->label('Tentar registro novamente')
                    ->icon(Heroicon::ArrowPath)
                    ->color('warning')
                    ->visible(fn (PixCharge $record): bool => in_array($record->status, [
                        PixChargeStatus::PENDING_REGISTRATION,
                        PixChargeStatus::REGISTRATION_FAILED,
                    ], true))
                    ->action(function (PixCharge $record): void {
                        RegisterPixChargeJob::dispatch($record->id);

                        Notification::make()
                            ->title('Registro da cobrança PIX enfileirado.')
                            ->success()
                            ->send();
                    }),
                Action::make('view_qr_code')
                    ->label('Visualizar QR Code')
                    ->icon(Heroicon::QrCode)
                    ->visible(fn (PixCharge $record): bool => filled($record->qr_code)
                        || filled($record->pix_copy_paste))
                    ->modalHeading('QR Code PIX')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Fechar')
                    ->modalContent(function (PixCharge $record): HtmlString {
                        $qrCode = (string) $record->qr_code;
                        $imageSource = str_starts_with($qrCode, 'data:')
                            ? $qrCode
                            : 'data:image/png;base64,'.$qrCode;
                        $image = filled($qrCode)
                            ? '<img src="'.e($imageSource).'" alt="QR Code PIX" style="display:block; width:280px; height:280px; margin:0 auto; image-rendering:auto;">'
                            : '<p>QR Code indisponível.</p>';
                        $copyPaste = e((string) $record->pix_copy_paste);

                        return new HtmlString(
                            '<div style="display:grid; gap:16px;">'
                            .$image
                            .'<div><strong>Copia e cola</strong><textarea readonly style="display:block; width:100%; min-height:96px; margin-top:8px;" onclick="this.select()">'
                            .$copyPaste
                            .'</textarea></div></div>'
                        );
                    }),
                Action::make('view_details')
                    ->label('Ver detalhes')
                    ->icon(Heroicon::InformationCircle)
                    ->modalHeading('Detalhes da cobrança PIX')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Fechar')
                    ->modalContent(function (PixCharge $record): HtmlString {
                        $details = json_encode([
                            'status' => $record->status?->description(),
                            'pix_copy_paste' => $record->pix_copy_paste,
                            'qr_code' => $record->qr_code,
                            'provider_status_code' => $record->provider_status_code,
                            'provider_status_message' => $record->provider_status_message,
                            'last_error' => $record->last_error,
                            'provider_payload' => $record->provider_payload,
                        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';

                        return new HtmlString('<pre style="white-space: pre-wrap; font-size: 12px;">'.e($details).'</pre>');
                    }),
            ])->icon(Heroicon::EllipsisVertical), position: RecordActionsPosition::BeforeColumns)
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Nenhuma cobrança PIX encontrada');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('company_id', Filament::getTenant()->id)
            ->with([
                'installment.accountReceivable.invoice',
                'installment.accountReceivable.customer',
                'connection.bank',
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPixCharges::route('/'),
        ];
    }
}
