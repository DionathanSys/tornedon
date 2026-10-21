<?php

namespace App\Filament\Clusters\Financial\Resources\CommitmentImports;

use App\Enum\Commitment\ImportType;
use App\Enum\Commitment\ProcessingStatus;
use App\Filament\Clusters\Financial\CommitmentsCluster;
use App\Filament\Clusters\Financial\Resources\CommitmentImports\Actions\ImportCommitmentFilesAction;
use App\Filament\Clusters\Financial\Resources\CommitmentImports\Pages\ListCommitmentImports;
use App\Filament\Clusters\Financial\Resources\CommitmentImports\Pages\ViewCommitmentImport;
use App\Filament\Clusters\Financial\Resources\CommitmentImports\RelationManagers\FilesRelationManager;
use App\Models\CommitmentImport;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CommitmentImportResource extends Resource
{
    protected static ?string $model = CommitmentImport::class;

    protected static ?string $cluster = CommitmentsCluster::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-arrow-up';

    protected static ?string $navigationLabel = 'Importações de empenhos';

    protected static ?string $modelLabel = 'importação de empenhos';

    protected static ?string $pluralModelLabel = 'Importações de empenhos';

    protected static ?int $navigationSort = 2;

    public static function importType(): ImportType
    {
        return ImportType::COMMITMENTS;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('company_id', Filament::getTenant()->id)->where('type', static::importType()->value)
            ->withCount(['files', 'files as finished_files_count' => fn (Builder $query) => $query->whereIn('status', ['processed', 'needs_review', 'failed'])]);
    }

    public static function table(Table $table): Table
    {
        return $table->poll('5s')->defaultSort('id', 'desc')->columns([
            TextColumn::make('id')->label('Lote')->sortable(),
            TextColumn::make('customer.name')->label('Cliente')->searchable(),
            TextColumn::make('status')->label('Situação')->badge()->formatStateUsing(fn ($state) => $state->description())->color(fn ($state) => $state->color()),
            TextColumn::make('progress')->label('Arquivos finalizados')->state(fn (CommitmentImport $record) => $record->finished_files_count.' / '.$record->files_count),
            TextColumn::make('duplicates')->label('Duplicados')->state(fn (CommitmentImport $record) => collect($record->summary)->where('status', 'duplicate')->count()),
            TextColumn::make('rejected')->label('Rejeitados')->state(fn (CommitmentImport $record) => collect($record->summary)->where('status', 'rejected')->count()),
            TextColumn::make('created_at')->label('Importado em')->dateTime('d/m/Y H:i')->sortable(),
            TextColumn::make('createdBy.name')->label('Usuário')->toggleable(),
        ])->filters([
            SelectFilter::make('customer_id')->label('Cliente')->options(fn () => ImportCommitmentFilesAction::customerOptions()),
            SelectFilter::make('status')->label('Situação')->options(collect(ProcessingStatus::cases())->mapWithKeys(fn ($case) => [$case->value => $case->description()])->all()),
        ])->recordActions([ViewAction::make()->label('Ver arquivos')->iconButton()->tooltip('Ver arquivos')]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Importação de PDFs')->columns(3)->columnSpanFull()->schema([
                TextEntry::make('customer.name')->label('Cliente'),
                TextEntry::make('status')->label('Situação')->badge()->formatStateUsing(fn ($state) => $state->description())->color(fn ($state) => $state->color()),
                TextEntry::make('created_at')->label('Importado em')->dateTime('d/m/Y H:i'),
                TextEntry::make('createdBy.name')->label('Usuário')->placeholder('—'),
                ViewEntry::make('summary')->label('Resultado do envio')->columnSpanFull()->view('filament.commitments.import-summary')->viewData(['resource' => static::class]),
            ]),
        ]);
    }

    public static function getRelations(): array
    {
        return [FilesRelationManager::class];
    }

    public static function getPages(): array
    {
        return ['index' => ListCommitmentImports::route('/'), 'view' => ViewCommitmentImport::route('/{record}')];
    }
}
