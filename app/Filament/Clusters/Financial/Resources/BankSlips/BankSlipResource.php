<?php

namespace App\Filament\Clusters\Financial\Resources\BankSlips;

use App\Enum\Financial\BankSlipStatus;
use App\Filament\Clusters\Financial\Actions\BankChargeLifecycleActions;
use App\Filament\Clusters\Financial\CollectionsCluster;
use App\Filament\Clusters\Financial\Resources\BankSlips\Pages\ListBankSlips;
use App\Jobs\QueryBankSlipJob;
use App\Models\BankSlip;
use App\Services\Financial\Banking\BankingOperationService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;

class BankSlipResource extends Resource
{
    protected static ?string $model = BankSlip::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::BuildingLibrary;

    protected static ?string $cluster = CollectionsCluster::class;

    protected static ?string $modelLabel = 'Boleto';

    protected static ?string $pluralModelLabel = 'Boletos';

    protected static ?int $navigationSort = 1;

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
                TextColumn::make('operation_status')->label('Situação da solicitação')->badge()->placeholder('-'),
                TextColumn::make('provider_response_code')->label('Código da resposta')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('provider_status_code')
                    ->label('Status da cobrança no provedor')
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
                TrashedFilter::make()->label('Lixeira'),
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(collect(BankSlipStatus::cases())
                        ->mapWithKeys(fn (BankSlipStatus $status): array => [$status->value => $status->description()])
                        ->all())
                    ->multiple(),
            ])
            ->recordActions(ActionGroup::make([
                ...BankChargeLifecycleActions::make(),
                Action::make('synchronize_installment')->label('Sincronizar alterações da parcela')->icon(Heroicon::ArrowPath)
                    ->visible(fn (BankSlip $record) => ! $record->trashed() && $record->status === BankSlipStatus::REGISTERED)
                    ->schema([Textarea::make('reason')->label('Justificativa')->required()->minLength(10)->maxLength(2000)])
                    ->action(fn (BankSlip $record, array $data) => app(BankingOperationService::class)
                        ->requestUpdate($record, Filament::getTenant()->id, Filament::auth()->user(), $data['reason'])),
                Action::make('open_pdf')
                    ->label('Abrir PDF')
                    ->icon(Heroicon::DocumentArrowDown)
                    ->visible(fn (BankSlip $record): bool => filled($record->pdf_url))
                    ->url(fn (BankSlip $record): ?string => $record->pdf_url, shouldOpenInNewTab: true),
                Action::make('query_provider')
                    ->label('Consultar no provider')
                    ->icon(Heroicon::ArrowPath)
                    ->color('info')
                    ->visible(fn (BankSlip $record): bool => ! $record->trashed() && filled($record->provider_identification))
                    ->action(function (BankSlip $record): void {
                        Gate::authorize('update', $record);
                        QueryBankSlipJob::dispatch($record->id);

                        Notification::make()
                            ->title('Consulta do boleto enfileirada.')
                            ->success()
                            ->send();
                    }),
                Action::make('view_details')
                    ->label('Ver detalhes')
                    ->icon(Heroicon::InformationCircle)
                    ->modalHeading('Detalhes do boleto')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Fechar')
                    ->modalContent(function (BankSlip $record): HtmlString {
                        $details = json_encode([
                            'status' => $record->status?->description(),
                            'operation_kind' => $record->operation_kind,
                            'operation_status' => $record->operation_status,
                            'provider_identification' => $record->provider_identification,
                            'provider_charge_id' => $record->provider_charge_id,
                            'provider_operation_id' => $record->provider_operation_id,
                            'provider_response_code' => $record->provider_response_code,
                            'provider_status_code' => $record->provider_status_code,
                            'provider_status_message' => $record->provider_status_message,
                            'last_synchronized_at' => $record->last_synchronized_at?->toIso8601String(),
                            'last_error' => $record->last_error,
                            'provider_payload' => $record->provider_payload,
                        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';

                        return new HtmlString('<pre style="box-sizing: border-box; margin: 0; min-width: 0; max-width: 100%; max-height: 60vh; overflow: auto; white-space: pre-wrap; overflow-wrap: anywhere; word-break: break-word; font-size: 12px;">'.e($details).'</pre>');
                    }),
                Action::make('retry_registration')
                    ->label('Tentar registro novamente')
                    ->icon(Heroicon::ArrowPath)
                    ->color('warning')
                    ->visible(fn (BankSlip $record): bool => ! $record->trashed() && in_array($record->status, [
                        BankSlipStatus::PENDING_REGISTRATION,
                        BankSlipStatus::REGISTRATION_FAILED,
                        BankSlipStatus::UNCERTAIN,
                    ], true))
                    ->schema([Textarea::make('reason')->label('Justificativa')->required()->minLength(10)->maxLength(2000)])
                    ->action(function (BankSlip $record, array $data): void {
                        app(BankingOperationService::class)->retryRegistration($record, Filament::getTenant()->id, Filament::auth()->user(), $data['reason']);

                        Notification::make()
                            ->title('Registro do boleto enfileirado.')
                            ->success()
                            ->send();
                    }),
            ])->icon(Heroicon::EllipsisVertical), position: RecordActionsPosition::BeforeColumns)
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Nenhum boleto encontrado');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class])
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
