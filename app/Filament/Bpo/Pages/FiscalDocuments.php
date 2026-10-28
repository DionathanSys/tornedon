<?php

namespace App\Filament\Bpo\Pages;

use App\Models\FiscalDocument;
use App\Services\Access\UserAccessService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;

class FiscalDocuments extends Page implements HasTable
{
    use \App\Filament\Bpo\Concerns\HasWorkbenchHeader;
    use InteractsWithTable;

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
        abort_unless(static::canAccess(), 403);
    }

    public function table(Table $table): Table
    {
        return $table->query(FiscalDocument::where('company_id', Filament::getTenant()->id))->columns([
            TextColumn::make('document_number')->label('Número')->searchable()->placeholder('Em preparação'),
            TextColumn::make('document_type')->label('Documento')->formatStateUsing(fn ($state) => $state?->description()),
            TextColumn::make('customer.name')->label('Cliente')->searchable(),
            TextColumn::make('issued_at')->label('Emissão')->dateTime('d/m/Y H:i')->sortable(),
            TextColumn::make('emission_status')->label('Situação fiscal')->state(fn ($record) => $record->isNfse() ? $record->nfse_status : $record->nfe_status)
                ->badge()->formatStateUsing(fn ($state) => $state?->description() ?? 'Em preparação')->color(fn ($state) => $state?->color() ?? 'gray'),
        ])->recordActions([
            Action::make('details')->label('Consultar')->icon('heroicon-o-eye')->modalHeading('Documento fiscal')->modalSubmitAction(false)->modalCancelActionLabel('Fechar')
                ->before(fn (FiscalDocument $record) => Gate::authorize('view', $record))
                ->schema([
                    TextEntry::make('document_number')->label('Número')->placeholder('Em preparação'),
                    TextEntry::make('customer.name')->label('Cliente'),
                    TextEntry::make('document_key')->label('Chave do documento')->placeholder('Ainda não autorizada'),
                    TextEntry::make('errors_messages')->label('Retorno da emissão')->state(fn (FiscalDocument $record) => is_array($record->errors_messages)
                        ? json_encode($record->errors_messages, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) : $record->errors_messages)->placeholder('Sem rejeições registradas'),
                ]),
        ])->defaultSort('created_at', 'desc')->paginated([15, 30, 50]);
    }
}
