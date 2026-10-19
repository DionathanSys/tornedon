<?php

namespace App\Filament\Clusters\Financial\Resources\CustomerCommitments;

use App\Enum\Commitment\MatchStatus;
use App\Filament\Clusters\Financial\Resources\CommitmentImports\Actions\ImportCommitmentFilesAction;
use App\Filament\Clusters\Financial\Resources\CustomerCommitments\Actions\CommitmentReviewActions;
use App\Filament\Clusters\Financial\Resources\CustomerCommitments\Pages\ListCustomerCommitments;
use App\Filament\Clusters\Financial\Resources\CustomerCommitments\Pages\ViewCustomerCommitment;
use App\Filament\Clusters\Financial\Resources\CustomerCommitments\Schemas\CommitmentReviewSchema;
use App\Models\CommitmentImport;
use App\Models\CustomerCommitment;
use App\Services\Commitments\SuggestCommitmentMatchesService;
use BackedEnum;
use Filament\Actions\BulkAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use UnitEnum;

class CustomerCommitmentResource extends Resource
{
    protected static ?string $model = CustomerCommitment::class;

    protected static ?string $tenantRelationshipName = 'commitments';

    protected static string|UnitEnum|null $navigationGroup = 'Financeiro';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationLabel = 'Empenhos';

    protected static ?string $modelLabel = 'empenho';

    protected static ?string $pluralModelLabel = 'Empenhos e conciliações';

    protected static ?int $navigationSort = 7;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('company_id', Filament::getTenant()->id)
            ->with(['customer', 'suggestedServiceOrder.equipment', 'billing.serviceOrder.equipment', 'importFile']);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            TextColumn::make('number')->label('Empenho')->searchable()->description(fn (CustomerCommitment $record) => 'Exercício '.$record->fiscal_year),
            TextColumn::make('customer.name')->label('Cliente')->searchable(),
            TextColumn::make('issued_at')->label('Emissão')->date('d/m/Y')->sortable(),
            TextColumn::make('patrimony')->label('Patrimônio')->searchable()->placeholder('Não identificado'),
            TextColumn::make('amount')->label('Valor')->money('BRL', locale: 'pt_BR')->sortable(),
            TextColumn::make('status')->label('Conciliação')->badge()->formatStateUsing(fn ($state) => $state->description())->color(fn ($state) => $state->color()),
            TextColumn::make('order_number')->label('OS aprovada / sugerida')->state(fn (CustomerCommitment $record) => $record->billing?->serviceOrder?->number ?? $record->suggestedServiceOrder?->number)->placeholder('—')
                ->description(fn (CustomerCommitment $record) => ($record->billing?->serviceOrder ?? $record->suggestedServiceOrder)?->order_date?->format('d/m/Y')),
            TextColumn::make('suggestion_count')->label('Compatíveis')->badge(),
            TextColumn::make('match_diagnostics.reason')->label('Diagnóstico')->wrap()->limit(90)->placeholder('Clique em Buscar OS ou abra Revisar conciliação.')->toggleable(),
        ])->filters([
            SelectFilter::make('customer_id')->label('Cliente')->options(fn () => ImportCommitmentFilesAction::customerOptions()),
            SelectFilter::make('status')->label('Conciliação')->options(collect(MatchStatus::cases())->mapWithKeys(fn ($case) => [$case->value => $case->description()])->all()),
            SelectFilter::make('import_id')->label('Lote de importação')
                ->options(fn () => CommitmentImport::forCompany(Filament::getTenant()->id)->where('type', 'commitments')->orderByDesc('id')->pluck('id', 'id')->all())
                ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn (Builder $query, $value) => $query->whereHas('importFile', fn ($file) => $file->where('commitment_import_id', $value)))),
        ])->recordActions([
            ViewAction::make()->label('Revisar conciliação'),
            CommitmentReviewActions::refresh()->iconButton(),
        ])->toolbarActions([
            BulkAction::make('refresh_selected')->label('Buscar OS para selecionados')->icon('heroicon-o-arrow-path')
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
