<?php

namespace App\Filament\Bpo\Resources;

use App\Filament\Clusters\Financial\Resources\AccountPayables\Schemas\AccountPayableForm;
use App\Filament\Clusters\Financial\Resources\AccountReceivables\Schemas\AccountReceivableForm;
use App\Filament\Clusters\Financial\Resources\Components\AccountSummary;
use App\Services\AccountPayable\AccountPayableService;
use App\Services\AccountReceivable\AccountReceivableService;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

abstract class FinancialTitleResource extends Resource
{
    public static string $module;

    public static function service(): AccountPayableService|AccountReceivableService
    {
        return app(static::$module === 'account_payables' ? AccountPayableService::class : AccountReceivableService::class);
    }

    public static function form(Schema $schema): Schema
    {
        return static::$module === 'account_payables'
            ? AccountPayableForm::configure($schema)
            : AccountReceivableForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return AccountSummary::configure($schema);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('company_id', Filament::getTenant()->id);
    }

    public static function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => AccountSummary::query($query))
            ->columns([
                TextColumn::make('counterparty_label')->label(static::$module === 'account_payables' ? 'Fornecedor' : 'Cliente')
                    ->description(fn ($record) => $record->description)->searchable(query: fn ($query, $search) => $query->where(fn ($query) => $query
                    ->where('description', 'like', '%'.$search.'%')->orWhere('manual_counterparty_name', 'like', '%'.$search.'%')
                    ->orWhereHas(static::$module === 'account_payables' ? 'supplier' : 'customer', fn ($party) => $party->where('name', 'like', '%'.$search.'%')))),
                TextColumn::make('document_number')->label('Documento')->searchable()->placeholder('—'),
                TextColumn::make('next_open_due_date')->label('Próximo vencimento')->date('d/m/Y'),
                TextColumn::make('due_amount')->label('Valor do título')->money('BRL'),
                TextColumn::make('considered_balance')->label('Saldo em aberto')->state(fn ($record) => (float) $record->considered_balance / 100)->money('BRL'),
                TextColumn::make('status')->label('Situação')->badge()->formatStateUsing(fn ($state) => $state?->description())->color(fn ($state) => $state?->color() ?? 'gray'),
            ])->recordActions([ViewAction::make()->label('Abrir'), EditAction::make()->label('Editar')])
            ->recordUrl(fn ($record) => static::getUrl('view', ['record' => $record]))->defaultSort('due_date')->paginated([15, 30, 50]);
    }
}
