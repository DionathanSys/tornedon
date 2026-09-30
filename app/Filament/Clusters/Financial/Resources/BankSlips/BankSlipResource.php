<?php

namespace App\Filament\Clusters\Financial\Resources\BankSlips;

use App\Enum\Financial\BankSlipStatus;
use App\Filament\Clusters\Financial\Resources\BankSlips\Pages\ListBankSlips;
use App\Jobs\RegisterBankSlipJob;
use App\Models\BankSlip;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use UnitEnum;

class BankSlipResource extends Resource
{
    protected static ?string $model = BankSlip::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::BuildingLibrary;

    protected static string|UnitEnum|null $navigationGroup = 'Financeiro';

    protected static ?string $modelLabel = 'Boleto';

    protected static ?string $pluralModelLabel = 'Boletos';

    protected static ?int $navigationSort = 4;

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
                    ->formatStateUsing(fn (?BankSlipStatus $state): string => $state?->description() ?? '-')
                    ->color(fn (?BankSlipStatus $state): string => $state?->color() ?? 'gray')
                    ->sortable(),
                TextColumn::make('provider_status_code')
                    ->label('Código provider')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('provider_status_message')
                    ->label('Mensagem provider')
                    ->limit(60)
                    ->tooltip(fn (?string $state): ?string => $state)
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('last_error')
                    ->label('Erro')
                    ->color('danger')
                    ->limit(80)
                    ->tooltip(fn (?string $state): ?string => $state)
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('connection.bank.name')
                    ->label('Banco')
                    ->placeholder('-'),
                TextColumn::make('digitable_line')
                    ->label('Linha digitável')
                    ->copyable()
                    ->copyMessage('Linha digitável copiada')
                    ->searchable()
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('pdf_url')
                    ->label('PDF')
                    ->url(fn (?string $state): ?string => filled($state) ? $state : null, shouldOpenInNewTab: true)
                    ->formatStateUsing(fn (?string $state): string => filled($state) ? 'Abrir PDF' : '-')
                    ->placeholder('-'),
                TextColumn::make('registered_at')
                    ->label('Registrado em')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('-')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(collect(BankSlipStatus::cases())
                        ->mapWithKeys(fn (BankSlipStatus $status): array => [$status->value => $status->description()])
                        ->all())
                    ->multiple(),
            ])
            ->recordActions([
                Action::make('view_details')
                    ->label('Ver detalhes')
                    ->icon(Heroicon::InformationCircle)
                    ->modalHeading('Detalhes do boleto')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Fechar')
                    ->modalContent(function (BankSlip $record): HtmlString {
                        $details = json_encode([
                            'status' => $record->status?->description(),
                            'provider_status_code' => $record->provider_status_code,
                            'provider_status_message' => $record->provider_status_message,
                            'last_error' => $record->last_error,
                            'provider_payload' => $record->provider_payload,
                        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';

                        return new HtmlString('<pre style="white-space: pre-wrap; font-size: 12px;">'.e($details).'</pre>');
                    }),
                Action::make('retry_registration')
                    ->label('Tentar registro novamente')
                    ->icon(Heroicon::ArrowPath)
                    ->color('warning')
                    ->visible(fn (BankSlip $record): bool => in_array($record->status, [
                        BankSlipStatus::PENDING_REGISTRATION,
                        BankSlipStatus::REGISTRATION_FAILED,
                        BankSlipStatus::UPDATE_PENDING,
                    ], true))
                    ->action(function (BankSlip $record): void {
                        RegisterBankSlipJob::dispatch($record->id);

                        Notification::make()
                            ->title('Registro do boleto enfileirado.')
                            ->success()
                            ->send();
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Nenhum boleto encontrado');
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
            'index' => ListBankSlips::route('/'),
        ];
    }
}
