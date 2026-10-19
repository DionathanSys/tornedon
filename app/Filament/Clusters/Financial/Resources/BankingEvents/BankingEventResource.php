<?php

namespace App\Filament\Clusters\Financial\Resources\BankingEvents;

use App\Filament\Clusters\Financial\BankingCluster;
use App\Filament\Clusters\Financial\Resources\BankingEvents\Pages\ListBankingEvents;
use App\Models\BankingEvent;
use App\Models\BankSlip;
use App\Services\Financial\Banking\BankingOperationService;
use App\Services\Financial\Banking\BankingResolutionService;
use App\Services\Financial\Banking\Providers\IntegraBancosExceptionFormatter;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

class BankingEventResource extends Resource
{
    protected static ?string $model = BankingEvent::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static ?string $cluster = BankingCluster::class;

    protected static ?string $modelLabel = 'Pendência bancária';

    protected static ?string $pluralModelLabel = 'Pendências bancárias';

    protected static ?int $navigationSort = 4;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('company_id', Filament::getTenant()->id)
            ->with(['bankSlip', 'pixCharge', 'connection.bank']);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->label('Evento')->sortable(),
            TextColumn::make('connection.bank.name')->label('Banco'),
            TextColumn::make('charge')->label('Cobrança')->getStateUsing(fn (BankingEvent $record) => ($record->bankSlip ?? $record->pixCharge)?->provider_identification ?? 'Sem vínculo')->searchable(query: fn ($query, $search) => $query->where(fn ($query) => $query
                ->whereHas('bankSlip', fn ($charge) => $charge->where('provider_identification', 'like', "%{$search}%"))
                ->orWhereHas('pixCharge', fn ($charge) => $charge->where('provider_identification', 'like', "%{$search}%")))),
            TextColumn::make('source')->label('Origem'),
            TextColumn::make('operation')->label('Operação'),
            TextColumn::make('status')->label('Situação do evento')->badge(),
            TextColumn::make('charge_status')->label('Situação da cobrança')->getStateUsing(fn (BankingEvent $record) => ($record->bankSlip ?? $record->pixCharge)?->status?->description()),
            TextColumn::make('error')->label('Motivo')->limit(90)->tooltip(fn ($state) => $state),
            TextColumn::make('attempts')->label('Tentativas'),
            TextColumn::make('received_at')->label('Recebido em')->dateTime('d/m/Y H:i')->sortable(),
        ])->filters([
            SelectFilter::make('status')->options(['pending' => 'Pendente', 'failed' => 'Falha', 'needs_review' => 'Revisão', 'processed' => 'Processado', 'resolved' => 'Resolvido', 'ignored' => 'Encerrado sem efeito financeiro']),
        ])->recordActions([
            ActionGroup::make([
                Action::make('details')->label('Histórico e retorno')->icon('heroicon-o-information-circle')
                    ->modalSubmitAction(false)->modalCancelActionLabel('Fechar')
                    ->modalContent(function (BankingEvent $record): HtmlString {
                        app(BankingResolutionService::class)->authorize($record, Filament::getTenant()->id, Filament::auth()->user());
                        $charge = $record->bankSlip ?? $record->pixCharge;
                        $history = self::getModel()::query()->where('company_id', Filament::getTenant()->id)
                            ->when($charge, fn ($query) => $query->where($charge instanceof BankSlip ? 'bank_slip_id' : 'pix_charge_id', $charge->id), fn ($query) => $query->whereKey($record->id))
                            ->latest('id')->limit(30)->get(['id', 'source', 'operation', 'status', 'error', 'attempts', 'received_at']);

                        return new HtmlString('<pre style="white-space:pre-wrap">'.e(json_encode([
                            'evento' => IntegraBancosExceptionFormatter::sanitizeData($record->payload), 'historico_recente' => $history,
                        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)).'</pre>');
                    }),
                Action::make('query')->label('Consultar cobrança/operação')->icon('heroicon-o-arrow-path')
                    ->visible(fn (BankingEvent $record) => $record->bank_slip_id || $record->pix_charge_id)
                    ->action(function (BankingEvent $record): void {
                        app(BankingResolutionService::class)->authorize($record, Filament::getTenant()->id, Filament::auth()->user());
                        app(BankingOperationService::class)->query($record->bankSlip ?? $record->pixCharge);
                        Notification::make()->title('Consulta enfileirada.')->success()->send();
                    }),
                Action::make('reprocess')->label('Reprocessar evento')->icon('heroicon-o-arrow-path')
                    ->visible(fn (BankingEvent $record) => in_array($record->status, ['pending', 'failed', 'needs_review'], true))
                    ->schema([Textarea::make('reason')->label('Justificativa')->required()->minLength(10)->maxLength(2000)])
                    ->action(fn (BankingEvent $record, array $data) => app(BankingResolutionService::class)->reprocess($record, Filament::getTenant()->id, Filament::auth()->user(), $data['reason'])),
                Action::make('acknowledge')->label('Encerrar sem efeito financeiro')->icon('heroicon-o-document-check')
                    ->visible(fn (BankingEvent $record) => $record->status === 'needs_review' && ((! $record->bank_slip_id && ! $record->pix_charge_id) || ! in_array(data_get($record->payload, 'charge_status'), ['3', '7'], true)))
                    ->modalDescription('Preserva o evento e registra a justificativa. Não cria, exclui ou estorna pagamentos.')
                    ->schema([Textarea::make('reason')->label('Tratamento realizado / justificativa')->required()->minLength(10)->maxLength(2000)])
                    ->action(fn (BankingEvent $record, array $data) => app(BankingResolutionService::class)->acknowledge($record, Filament::getTenant()->id, Filament::auth()->user(), $data['reason'])),
                Action::make('retry_registration')->label('Reenviar com validação de recuperação')->icon('heroicon-o-paper-airplane')
                    ->visible(fn (BankingEvent $record) => in_array(($record->bankSlip ?? $record->pixCharge)?->status?->value, ['uncertain', 'registration_failed'], true))
                    ->schema([Textarea::make('reason')->label('Justificativa')->required()->minLength(10)->maxLength(2000)])
                    ->action(function (BankingEvent $record, array $data): void {
                        app(BankingResolutionService::class)->authorize($record, Filament::getTenant()->id, Filament::auth()->user());
                        app(BankingOperationService::class)->retryRegistration($record->bankSlip ?? $record->pixCharge, Filament::getTenant()->id, Filament::auth()->user(), $data['reason']);
                    }),
                Action::make('resolve_payment')->label('Resolver recebimento')->icon('heroicon-o-check-circle')
                    ->visible(fn (BankingEvent $record) => $record->status === 'needs_review' && data_get($record->payload, 'charge_status') === '3' && ($record->bank_slip_id || $record->pix_charge_id))
                    ->schema([
                        Select::make('payment_id')->label('Vincular baixa existente (opcional)')->options(fn (BankingEvent $record) => self::payments($record))->placeholder('Registrar novo recebimento'),
                        TextInput::make('amount')->label('Valor efetivamente recebido')->numeric()->required()->minValue(0.01),
                        TextInput::make('payment_date')->label('Data bancária (AAAA-MM-DD)')->required()->rules(['date_format:Y-m-d']),
                        TextInput::make('interest_amount')->label('Juros')->numeric()->minValue(0)->default(0),
                        TextInput::make('fine_amount')->label('Multa')->numeric()->minValue(0)->default(0),
                        TextInput::make('discount_amount')->label('Desconto')->numeric()->minValue(0)->default(0),
                        Textarea::make('reason')->label('Composição / justificativa')->required()->minLength(10)->maxLength(2000),
                    ])->action(fn (BankingEvent $record, array $data) => app(BankingResolutionService::class)->confirmPayment($record, Filament::getTenant()->id, Filament::auth()->user(), $data)),
                Action::make('resolve_return')->label('Registrar devolução já confirmada')->icon('heroicon-o-arrow-uturn-left')
                    ->modalDescription('Registra a contrapartida de uma devolução integral já realizada pelo banco. Não solicita nem envia dinheiro.')
                    ->visible(fn (BankingEvent $record) => $record->status === 'needs_review' && data_get($record->payload, 'charge_status') === '7' && ($record->bank_slip_id || $record->pix_charge_id))
                    ->schema([
                        Select::make('payment_id')->label('Recebimento devolvido integralmente')->options(fn (BankingEvent $record) => self::payments($record, true))->required(),
                        TextInput::make('return_date')->label('Data da devolução (AAAA-MM-DD)')->required()->rules(['date_format:Y-m-d']),
                        Textarea::make('reason')->label('Comprovação / justificativa')->required()->minLength(10)->maxLength(2000),
                    ])->action(fn (BankingEvent $record, array $data) => app(BankingResolutionService::class)->confirmReturn($record, Filament::getTenant()->id, Filament::auth()->user(), (int) $data['payment_id'], $data['return_date'], $data['reason'])),
            ]),
        ])->defaultSort('id', 'desc')->emptyStateHeading('Nenhuma pendência bancária');
    }

    private static function payments(BankingEvent $event, bool $returns = false): array
    {
        if ((int) $event->company_id !== (int) Filament::getTenant()?->id) {
            return [];
        }
        $charge = $event->bankSlip ?? $event->pixCharge;
        if (! $charge) {
            return [];
        }

        return $charge->installment?->payments()->where('company_id', $event->company_id)
            ->when($returns, fn ($query) => $query->where($charge instanceof BankSlip ? 'bank_slip_id' : 'pix_charge_id', $charge->id)->whereNotNull('bank_confirmed_at')->where('returned_amount', 0))
            ->get()->mapWithKeys(fn ($payment) => [$payment->id => '#'.$payment->id.' — R$ '.number_format($payment->amount, 2, ',', '.').' — '.$payment->payment_date?->format('d/m/Y')])->all() ?? [];
    }

    public static function getPages(): array
    {
        return ['index' => ListBankingEvents::route('/')];
    }
}
