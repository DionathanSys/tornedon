<?php

namespace App\Filament\Clusters\Financial\Resources\CustomerCommitments;

use App\Enum\Commitment\BillingStatus;
use App\Enum\Commitment\MatchStatus;
use App\Filament\Clusters\Financial\CommitmentsCluster;
use App\Filament\Clusters\Financial\Resources\CommitmentImports\Actions\ImportCommitmentFilesAction;
use App\Filament\Clusters\Financial\Resources\CustomerCommitments\Actions\CommitmentBillingActions;
use App\Filament\Clusters\Financial\Resources\CustomerCommitments\Actions\CommitmentReviewActions;
use App\Filament\Clusters\Financial\Resources\CustomerCommitments\Pages\ListCustomerCommitments;
use App\Filament\Clusters\Financial\Resources\CustomerCommitments\Pages\ViewCustomerCommitment;
use App\Filament\Clusters\Financial\Resources\CustomerCommitments\Schemas\CommitmentReviewSchema;
use App\Models\CommitmentBillingBatch;
use App\Models\CommitmentImport;
use App\Models\CustomerCommitment;
use App\Services\Commitments\SuggestCommitmentMatchesService;
use BackedEnum;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class CustomerCommitmentResource extends Resource
{
    protected static ?string $model = CustomerCommitment::class;

    protected static ?string $tenantRelationshipName = 'commitments';

    protected static ?string $cluster = CommitmentsCluster::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationLabel = 'Empenhos';

    protected static ?string $modelLabel = 'empenho';

    protected static ?string $pluralModelLabel = 'Empenhos e conciliações';

    protected static ?int $navigationSort = 1;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('company_id', Filament::getTenant()->id)
            ->with(['customer', 'suggestedServiceOrder.equipment', 'billing.serviceOrder.equipment', 'billing.latestBatchItem', 'billing.activeBatch', 'billing.invoice.fiscalDocuments', 'importFile']);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->recordUrl(null)->poll('10s')->columns([
            TextColumn::make('number')->label('Empenho')->searchable()->description(fn (CustomerCommitment $record) => 'Exercício '.$record->fiscal_year),
            TextColumn::make('customer.name')->label('Cliente')->searchable(),
            TextColumn::make('issued_at')->label('Emissão')->date('d/m/Y')->sortable(),
            TextColumn::make('patrimony')->label('Patrimônio')->searchable()->placeholder('Não identificado'),
            TextColumn::make('amount')->label('Valor')->money('BRL', locale: 'pt_BR')->sortable(),
            TextColumn::make('status')->label('Conciliação')->badge()->formatStateUsing(fn ($state) => $state->description())->color(fn ($state) => $state->color()),
            TextColumn::make('billing.status')->label('Faturamento')->badge()->formatStateUsing(fn ($state) => $state->description())->color(fn ($state) => $state->color())->placeholder('Sem vínculo'),
            TextColumn::make('execution')->label('Execução')->state(fn (CustomerCommitment $record) => match ($record->billing?->latestBatchItem?->status) {
                'queued' => 'Na fila', 'processing' => 'Processando', 'completed' => 'Concluído', 'failed' => 'Falhou', default => null,
            })->placeholder('—')->description(fn (CustomerCommitment $record) => $record->billing?->active_batch_id ? 'Lote #'.$record->billing->active_batch_id : null),
            TextColumn::make('nfse_status')->label('NFS-e')->state(fn (CustomerCommitment $record) => $record->billing?->invoice?->fiscalDocuments->firstWhere('document_type.value', 'nfse')?->nfse_status?->description())->placeholder('Não encaminhada'),
            TextColumn::make('billing.last_error')->label('Erro de faturamento/emissão')->wrap()->limit(120)->placeholder('—')->toggleable(),
            TextColumn::make('order_number')->label('OS aprovada / sugerida')->state(fn (CustomerCommitment $record) => $record->billing?->serviceOrder?->number ?? $record->suggestedServiceOrder?->number)->placeholder('—')
                ->description(fn (CustomerCommitment $record) => ($record->billing?->serviceOrder ?? $record->suggestedServiceOrder)?->order_date?->format('d/m/Y')),
            TextColumn::make('suggestion_count')->label('Compatíveis')->badge(),
            TextColumn::make('order_amount_cents')->label('Valor OS (busca/aprovação)')->money('BRL', divideBy: 100, locale: 'pt_BR')
                ->state(fn (CustomerCommitment $record) => $record->billing
                    ? data_get($record->billing->approval_snapshot, 'selected_order.total_amount_cents')
                    : collect(data_get($record->match_diagnostics, 'candidates', []))->firstWhere('service_order_id', $record->suggested_service_order_id)['total_amount_cents'] ?? null)->placeholder('—')->toggleable(),
            TextColumn::make('matched_at')->label('Última busca')->dateTime('d/m/Y H:i')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('match_diagnostics.reason')->label('Diagnóstico')->wrap()->limit(90)->placeholder('Clique em Buscar OS ou abra Revisar conciliação.')->toggleable(),
        ])->filters([
            SelectFilter::make('customer_id')->label('Cliente')->options(fn () => ImportCommitmentFilesAction::customerOptions()),
            SelectFilter::make('status')->label('Conciliação')->options(collect(MatchStatus::cases())->mapWithKeys(fn ($case) => [$case->value => $case->description()])->all()),
            SelectFilter::make('billing_status')->label('Etapa de faturamento')->options(collect(BillingStatus::cases())->mapWithKeys(fn ($case) => [$case->value => $case->description()])->all())
                ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn ($query, $value) => $query->whereHas('billing', fn ($billing) => $billing->where('status', $value)))),
            SelectFilter::make('billing_batch')->label('Lote de faturamento')->options(fn () => CommitmentBillingBatch::query()->where('company_id', Filament::getTenant()->id)->latest('id')->limit(100)->pluck('id', 'id')->all())
                ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn ($query, $value) => $query->whereHas('billingBatchItems', fn ($item) => $item->where('company_id', Filament::getTenant()->id)->where('commitment_billing_batch_id', $value)))),
            Filter::make('billing_errors')->label('Com falha de faturamento/emissão')->query(fn (Builder $query) => $query->whereHas('billing', fn ($billing) => $billing->whereNotNull('last_error'))),
            SelectFilter::make('import_id')->label('Lote de importação')
                ->options(fn () => CommitmentImport::forCompany(Filament::getTenant()->id)->where('type', 'commitments')->orderByDesc('id')->pluck('id', 'id')->all())
                ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn (Builder $query, $value) => $query->whereHas('importFile', fn ($file) => $file->where('commitment_import_id', $value)))),
            Filter::make('issued_period')->label('Período de emissão')->schema([
                DatePicker::make('from')->label('Emissão a partir de'),
                DatePicker::make('until')->label('Emissão até'),
            ])->query(fn (Builder $query, array $data) => $query
                ->when($data['from'] ?? null, fn ($query, $date) => $query->whereDate('issued_at', '>=', $date))
                ->when($data['until'] ?? null, fn ($query, $date) => $query->whereDate('issued_at', '<=', $date))),
            Filter::make('without_eligible_order')->label('Com candidatas, sem OS elegível')
                ->query(fn (Builder $query) => $query->where('status', MatchStatus::UNMATCHED->value)->where('suggestion_count', 0)->whereJsonLength('match_diagnostics->candidates', '>', 0)),
        ])->recordActions([
            ActionGroup::make([
                CommitmentReviewActions::review(),
                CommitmentBillingActions::make(),
                CommitmentBillingActions::invoice(),
                CommitmentReviewActions::approve(),
                CommitmentReviewActions::refresh(),
                CommitmentReviewActions::originalPdf(),
                CommitmentReviewActions::correct(),
                CommitmentReviewActions::unlink(),
                CommitmentReviewActions::ignore(),
                CommitmentReviewActions::ignore(reopen: true),
            ])->label('Ações do empenho')->tooltip('Ações do empenho')->size(Size::ExtraSmall)->icon(Heroicon::Bars3),
        ], RecordActionsPosition::BeforeCells)->recordActionsAlignment('start')->toolbarActions([
            CommitmentBillingActions::bulk()->iconButton()->tooltip('Faturar selecionados'),
            BulkAction::make('refresh_selected')->label('Buscar OS para selecionados')->icon('heroicon-o-arrow-path')->iconButton()->tooltip('Buscar OS para selecionados')
                ->authorize('viewAny', CustomerCommitment::class)->deselectRecordsAfterCompletion()
                ->action(function (Collection $records): void {
                    $errors = [];
                    foreach ($records->sortBy('id') as $record) {
                        try {
                            app(SuggestCommitmentMatchesService::class)->refresh(Filament::getTenant()->id, $record->id, Auth::id());
                        } catch (ValidationException $exception) {
                            $errors[] = $record->number.': '.$exception->validator->errors()->first();
                        }
                    }
                    Notification::make()->title('Busca de OS concluída')->body($errors === [] ? 'Conciliações selecionadas atualizadas.' : implode("\n", $errors))->color($errors === [] ? 'success' : 'warning')->send();
                }),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components(CommitmentReviewSchema::components());
    }

    public static function getPages(): array
    {
        return ['index' => ListCustomerCommitments::route('/'), 'view' => ViewCustomerCommitment::route('/{record}')];
    }
}
