<?php

namespace App\Filament\Bpo\Pages;

use App\Enum\FiscalDocument\DocumentModel;
use App\Enum\FiscalDocument\NfeStatus;
use App\Enum\FiscalDocument\OperationType;
use App\Enum\FiscalDocument\Status;
use App\Filament\Bpo\Concerns\HasWorkbenchHeader;
use App\Filament\Clusters\Sales\Resources\FiscalDocuments\Pages\Actions\ExportOutputFiscalDocumentsAction;
use App\Filament\Clusters\Sales\Resources\FiscalDocuments\Pages\Actions\ExportOutputFiscalDocumentsPdfAction;
use App\Filament\Clusters\Sales\Resources\FiscalDocuments\Pages\Actions\ExportOutputFiscalDocumentsXmlAction;
use App\Filament\Support\Actions\FiscalDocumentRecordActions;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentXmlExport;
use App\Notification\NotifyService as notify;
use App\Services\Access\CompanyAuthorization;
use App\Services\Access\UserAccessService;
use App\Services\FiscalDocument\Actions\ReconcileNfseRpsSequenceAction;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Malzariey\FilamentDaterangepickerFilter\Filters\DateRangeFilter;

class FiscalDocuments extends Page implements HasTable
{
    use HasWorkbenchHeader;
    use InteractsWithTable;

    /** Private, request-local only: never persisted in the Livewire snapshot. */
    private ?array $fiscalPermissions = null;

    public function workbenchEyebrow(): string
    {
        return 'DOCUMENTOS · FISCAL';
    }

    public function workbenchDescription(): string
    {
        return 'Consulte documentos, acompanhe a situação fiscal e confira os retornos de emissão.';
    }

    protected static string $layout = 'components.bpo.layout';

    protected string $view = 'filament.bpo.pages.fiscal-documents';

    protected static ?string $title = 'Acompanhamento fiscal';

    public static function canAccess(): bool
    {
        return Filament::getTenant() && app(UserAccessService::class)->allows(Filament::auth()->user(), Filament::getTenant(), 'fiscal_documents.view', 'bpo');
    }

    public function booted(): void
    {
        $this->fiscalPermissions = null;
        abort_unless(static::canAccess(), 403);
    }

    public function table(Table $table): Table
    {
        return $table->query(FiscalDocument::query()
            ->select(['id', 'company_id', 'created_by', 'customer_id', 'document_number', 'document_series',
                'operation_type', 'document_type', 'nfse_model', 'issued_at', 'nfe_status', 'nfse_status', 'status',
                'rps_number', 'rps_series', 'document_key', 'nfe_protocolo', 'nfse_protocol',
                'authorized_at', 'created_at', 'operational_reset_at'])
            ->where('company_id', Filament::getTenant()->id)->with('customer:id,name')
            ->withExists(['payload as has_corrections' => fn (Builder $query) => $query->whereJsonLength('nfe_payload->correcoes', '>', 0)]))->columns([
                TextColumn::make('document_number')->label('Número')->searchable()->placeholder('Em preparação'),
                TextColumn::make('operation_type')->label('Operação')->badge()
                    ->formatStateUsing(fn ($state) => $state?->description())->color(fn ($state) => $state?->color() ?? 'gray'),
                TextColumn::make('document_type')->label('Documento')->formatStateUsing(fn ($state) => $state?->description()),
                TextColumn::make('customer.name')->label('Cliente / Fornecedor')->searchable()->sortable(),
                TextColumn::make('issued_at')->label('Emissão')->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('emission_status')->label('Situação fiscal')->state(fn ($record) => $record->isNfse() ? $record->nfse_status : $record->nfe_status)
                    ->badge()->formatStateUsing(fn ($state) => $state?->description() ?? 'Em preparação')->color(fn ($state) => $state?->color() ?? 'gray'),
                TextColumn::make('document_series')->label('Série')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('rps_number')->label('RPS')->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('rps_series')->label('Série RPS')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')->label('Situação interna')->badge()
                    ->formatStateUsing(fn ($state) => $state?->description())->color(fn ($state) => $state?->color() ?? 'gray')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('document_key')->label('Chave')->searchable()->copyable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('nfe_protocolo')->label('Protocolo NF-e')->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('nfse_protocol')->label('Protocolo NFS-e')->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('authorized_at')->label('Autorizado em')->dateTime('d/m/Y H:i')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])->filters([
                SelectFilter::make('operation_type')->label('Operação')->options(OperationType::toSelectArray()),
                SelectFilter::make('document_type')->label('Documento')->options(DocumentModel::toSelectArray()),
                SelectFilter::make('emission_status')->label('Situação fiscal')->options(NfeStatus::toSelectArray())->multiple()
                    ->query(function (Builder $query, array $data): Builder {
                        $values = $data['values'] ?? [];

                        return $query->when($values !== [], fn (Builder $query) => $query->where(function (Builder $query) use ($values): void {
                            $query->where(fn (Builder $query) => $query->where('document_type', DocumentModel::NFSE->value)->whereIn('nfse_status', $values))
                                ->orWhere(fn (Builder $query) => $query->where('document_type', DocumentModel::NFE->value)->whereIn('nfe_status', $values));

                            if (in_array(NfeStatus::PENDING->value, $values, true)) {
                                $query->orWhere(fn (Builder $query) => $query->whereNull('nfe_status')->whereNull('nfse_status'));
                            }
                        }));
                    }),
                SelectFilter::make('status')->label('Situação interna')->options(Status::toSelectArray())->multiple(),
                DateRangeFilter::make('issued_at')->label('Emissão')->autoApply(),
            ])->recordActions([
                Action::make('details')->label('Detalhes')->icon('heroicon-o-eye')->modalHeading('Documento fiscal')->modalSubmitAction(false)->modalCancelActionLabel('Fechar')
                    ->authorize(fn (FiscalDocument $record) => $this->canOperate($record, 'view'))
                    ->beforeFormFilled(function (FiscalDocument $record): void {
                        $this->authorizeOperation($record, 'view');
                        $record->refresh();
                    })
                    ->schema([
                        TextEntry::make('document_number')->label('Número')->placeholder('Em preparação'),
                        TextEntry::make('customer.name')->label('Cliente'),
                        TextEntry::make('document_key')->label('Chave do documento')->placeholder('Ainda não autorizada'),
                        TextEntry::make('nfe_protocolo')->label('Protocolo NF-e')->placeholder('—'),
                        TextEntry::make('nfse_protocol')->label('Protocolo NFS-e')->placeholder('—'),
                        TextEntry::make('rps_number')->label('RPS')->placeholder('—'),
                        TextEntry::make('authorized_at')->label('Autorizado em')->dateTime('d/m/Y H:i')->placeholder('—'),
                        TextEntry::make('errors_messages')->label('Retorno da emissão')->state(fn (FiscalDocument $record) => is_array($record->errors_messages)
                            ? json_encode($record->errors_messages, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) : $record->errors_messages)->placeholder('Sem rejeições registradas'),
                    ]),
                ActionGroup::make($this->fiscalActions())->label('Ações fiscais')->icon('heroicon-o-ellipsis-vertical'),
            ])->toolbarActions([
                Action::make('refresh')->label('Atualizar')->icon('heroicon-o-arrow-path')->action(fn () => null),
                ...$this->exportActions(),
            ])->defaultSort('created_at', 'desc')->paginated([15, 30, 50]);
    }

    private function canOperate(FiscalDocument $record, string $ability): bool
    {
        if ((int) $record->company_id !== (int) Filament::getTenant()?->id) {
            return false;
        }

        $access = app(CompanyAuthorization::class);
        $this->fiscalPermissions ??= $access->effectivePermissions(Filament::auth()->user(), Filament::getTenant());

        return $access->recordMatchesScopes($record, Filament::auth()->user(), $this->fiscalPermissions['fiscal_documents.view'] ?? [])
            && $access->recordMatchesScopes($record, Filament::auth()->user(), $this->fiscalPermissions['fiscal_documents.'.$ability] ?? []);
    }

    private function authorizeOperation(FiscalDocument $record, string $ability): void
    {
        abort_unless((int) $record->company_id === (int) Filament::getTenant()?->id, 403);
        Gate::authorize('view', $record);
        abort_unless(app(CompanyAuthorization::class)->allowsRecord(Filament::auth()->user(), Filament::getTenant(), 'fiscal_documents.'.$ability, $record), 403);

        if ($ability !== 'view') {
            abort_unless($record->operation_type === OperationType::SAIDA && ! $record->isOperationalHistory(), 403);
        }
    }

    private function fiscalActions(): array
    {
        $actions = FiscalDocumentRecordActions::make();

        foreach ($actions as $action) {
            $ability = match ($action->getName()) {
                'emitir', 'emitir_nfse' => 'issue',
                'cancelar_nfe', 'cancelar_nfse' => 'cancel',
                'corrigir_nfe', 'consultar', 'consultar_nfse' => 'update',
                'preview', 'preview_nfse', 'danfe', 'pdf_nfse', 'baixar_carta_correcao_nfe' => 'view',
                default => null,
            };
            $action->authorize(fn (FiscalDocument $record) => $ability !== null && $this->canOperate($record, $ability)
                && ($ability === 'view' || ($record->operation_type === OperationType::SAIDA && $record->operational_reset_at === null)))
                ->beforeFormFilled(fn (FiscalDocument $record) => $this->authorizeOperation($record, $ability))
                ->before(fn (FiscalDocument $record) => $this->authorizeOperation($record, $ability));

            if (in_array($action->getName(), ['preview', 'preview_nfse'], true)) {
                $action->modalSubmitAction(false)->modalCancelActionLabel('Fechar')->action(null);
            }
        }

        $actions[] = Action::make('reconciliar_rps_nfse')->label('Conciliar RPS')->icon('heroicon-o-wrench-screwdriver')->color('warning')
            ->visible(fn (FiscalDocument $record) => $record->isNfse() && $record->isNfsePendingReconciliation())
            ->authorize(fn (FiscalDocument $record) => $this->canOperate($record, 'update')
                && $record->operation_type === OperationType::SAIDA && $record->operational_reset_at === null)
            ->beforeFormFilled(fn (FiscalDocument $record) => $this->authorizeOperation($record, 'update'))
            ->before(fn (FiscalDocument $record) => $this->authorizeOperation($record, 'update'))
            ->schema([
                Textarea::make('reason')->label('Justificativa')->required()->minLength(15)->rows(4),
                Select::make('resolution')->label('Destino do documento')->options([
                    'keep' => 'Manter em conciliação com o RPS atual',
                    'clear' => 'Limpar RPS do documento para novo envio',
                ])->default('clear')->required()->native(false),
            ])->action(function (FiscalDocument $record, array $data): void {
                $action = app(ReconcileNfseRpsSequenceAction::class);
                $action->execute($record, $data['reason'], $data['resolution'] === 'clear');

                if ($action->isSuccess()) {
                    notify::success('Conciliação de RPS registrada com sucesso.');
                } else {
                    notify::error($action->getMessage() ?: 'Não foi possível conciliar o RPS.');
                }
            });

        return $actions;
    }

    private function exportActions(): array
    {
        return array_map(fn (Action $action) => $action->authorize(fn () => app(UserAccessService::class)
            ->allows(Filament::auth()->user(), Filament::getTenant(), 'fiscal_documents.export', 'bpo')), [
                ExportOutputFiscalDocumentsAction::make(),
                ExportOutputFiscalDocumentsPdfAction::make(),
                ExportOutputFiscalDocumentsXmlAction::make(),
                Action::make('xml_exports')->label('Minhas exportações XML')->icon('heroicon-o-archive-box')
                    ->modalHeading('Exportações de XML desta empresa')->modalSubmitAction(false)->modalCancelActionLabel('Fechar')
                    ->modalContent(fn () => view('filament.bpo.pages.fiscal-xml-exports', [
                        'exports' => FiscalDocumentXmlExport::query()->where('company_id', Filament::getTenant()->id)
                            ->where('user_id', Filament::auth()->id())->latest()->limit(20)->get(),
                    ])),
            ]);
    }
}
