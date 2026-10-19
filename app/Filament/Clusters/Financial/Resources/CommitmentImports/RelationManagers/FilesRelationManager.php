<?php

namespace App\Filament\Clusters\Financial\Resources\CommitmentImports\RelationManagers;

use App\Filament\Clusters\Financial\Resources\CommitmentImports\Schemas\ImportFileDetailsSchema;
use App\Models\CommitmentImportFile;
use App\Services\Commitments\ReprocessCommitmentImportFileService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class FilesRelationManager extends RelationManager
{
    protected static string $relationship = 'files';

    protected static ?string $title = 'Arquivos e processamento';

    public function mount(): void
    {
        Gate::authorize('view', $this->getOwnerRecord());
        parent::mount();
    }

    public function hydrate(): void
    {
        Gate::authorize('view', $this->getOwnerRecord());
    }

    public function table(Table $table): Table
    {
        return $table->poll('5s')->modifyQueryUsing(fn ($query) => $query->where('company_id', Filament::getTenant()->id)->with('attachments'))
            ->columns([
                TextColumn::make('original_name')->label('PDF')->searchable()->wrap(),
                TextColumn::make('status')->label('Situação')->badge()->formatStateUsing(fn ($state) => $state->description())->color(fn ($state) => $state->color()),
                TextColumn::make('identification')->label('Identificação')->state(fn (CommitmentImportFile $record) => data_get($record->extracted_data, 'number') ? $record->extracted_data['number'].'/'.$record->extracted_data['fiscal_year'] : ($record->line_count ? $record->line_count.' pagamentos' : '—')),
                TextColumn::make('page_count')->label('Páginas'),
                TextColumn::make('declared_total')->label('Total declarado')->money('BRL'),
                TextColumn::make('extracted_total')->label('Total extraído')->money('BRL'),
                TextColumn::make('attempts')->label('Tentativas'),
                TextColumn::make('last_error')->label('Último erro')->wrap()->limit(120)->tooltip(fn ($state) => $state),
            ])->recordActions([
                Action::make('details')->authorize('view')->label('Dados extraídos')->icon('heroicon-o-eye')->modalWidth('7xl')->modalSubmitAction(false)->modalCancelActionLabel('Fechar')
                    ->stickyModalHeader()->stickyModalFooter()
                    ->fillForm(fn (CommitmentImportFile $record) => ['extracted_text' => $record->extracted_text])
                    ->schema(ImportFileDetailsSchema::components()),
                Action::make('preview_pdf')->authorize('view')->label('Visualizar PDF')->icon('heroicon-o-document')->openUrlInNewTab()
                    ->visible(fn (CommitmentImportFile $record) => $record->currentAttachments()->exists())
                    ->url(fn (CommitmentImportFile $record) => $record->currentAttachments()->first()?->preview_url),
                Action::make('download_pdf')->authorize('view')->label('Baixar PDF')->openUrlInNewTab()
                    ->visible(fn (CommitmentImportFile $record) => $record->currentAttachments()->exists())
                    ->url(fn (CommitmentImportFile $record) => $record->currentAttachments()->first()?->url),
                Action::make('reprocess')->label('Reprocessar')->icon('heroicon-o-arrow-path')->requiresConfirmation()
                    ->visible(fn (CommitmentImportFile $record) => $record->canReprocess())
                    ->modalDescription('Executa uma nova leitura do PDF original. Arquivos com vínculos ou revisão já realizada são preservados.')
                    ->action(function (CommitmentImportFile $record): void {
                        Gate::authorize('update', $record);
                        app(ReprocessCommitmentImportFileService::class)->execute($record->id, Filament::getTenant()->id, $record->customer_id, Auth::id());
                        Notification::make()->title('Reprocessamento solicitado')->success()->send();
                    }),
            ]);
    }
}
