<?php

namespace App\Filament\Bpo\Resources;

use App\Enum\Invoice\Status;
use App\Filament\Bpo\Resources\Invoices\Pages;
use App\Filament\Clusters\Financial\Resources\Invoices\Pages\Actions\ExportSelectedInvoicesAction;
use App\Filament\Clusters\Financial\Resources\Invoices\Pages\Actions\ExportSelectedInvoicesPdfAction;
use App\Models\Invoice;
use App\Services\Access\CompanyAuthorization;
use App\Services\Access\UserAccessService;
use Filament\Facades\Filament;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Malzariey\FilamentDaterangepickerFilter\Filters\DateRangeFilter;

class InvoiceResource extends Resource
{
    protected static ?string $model = Invoice::class;

    protected static ?string $modelLabel = 'Fatura';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('company_id', Filament::getTenant()->id)->with('customer');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(2)->components([TextEntry::make('invoice_number')->label('Fatura'), TextEntry::make('customer.name')->label('Cliente'),
            TextEntry::make('invoice_date')->label('Data')->date('d/m/Y'), TextEntry::make('netValue')->label('Valor líquido')->money('BRL'),
            TextEntry::make('status')->label('Situação')->formatStateUsing(fn ($state) => $state?->description()),
            TextEntry::make('accountReceivables.document_number')->label('Recebíveis gerados')->listWithLineBreaks(),
            TextEntry::make('fiscalDocuments.document_number')->label('Documentos fiscais')->listWithLineBreaks()]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('invoice_number')->label('Fatura')->searchable()->sortable(), TextColumn::make('customer.name')->label('Cliente')->searchable(), TextColumn::make('invoice_date')->label('Data')->date('d/m/Y')->sortable(),
            TextColumn::make('status')->label('Situação')->badge()->formatStateUsing(fn ($state) => $state?->description())->color(fn ($state) => $state?->color() ?? 'gray')])
            ->filters([SelectFilter::make('status')->label('Situação')->options(Status::toSelectArray())->multiple(),
                DateRangeFilter::make('invoice_date')->label('Data da fatura')->autoApply()])
            ->recordUrl(fn ($record) => static::getUrl('view', ['record' => $record]))->defaultSort('id', 'desc')->defaultPaginationPageOption(15)->paginated([15, 30, 50])
            ->toolbarActions(array_map(fn ($action) => $action->authorize(fn ($livewire) => $livewire->canUseBpoAction('invoices.export'))
                ->before(function (Collection $records): void {
                    Gate::authorize('viewAny', Invoice::class);
                    abort_unless(app(UserAccessService::class)->allows(Filament::auth()->user(), Filament::getTenant(), 'invoices.export', 'bpo'), 403);
                    $access = app(CompanyAuthorization::class);
                    $scopes = $access->scopes(Filament::auth()->user(), Filament::getTenant(), 'invoices.export');
                    foreach ($records as $record) {
                        abort_unless((int) $record->company_id === (int) Filament::getTenant()->id && $access->recordMatchesScopes($record, Filament::auth()->user(), $scopes), 403);
                    }
                }), [
                    ExportSelectedInvoicesAction::make(),
                    ExportSelectedInvoicesPdfAction::make(),
                ]));
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListInvoices::route('/'), 'view' => Pages\ViewInvoice::route('/{record}'), 'edit' => Pages\ViewInvoice::route('/{record}/edit')];
    }
}
