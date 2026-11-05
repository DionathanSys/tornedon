<?php

namespace App\Filament\Bpo\Resources;

use App\Filament\Bpo\Resources\Services\Pages;
use App\Services\Access\CompanyAuthorization;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ServiceResource extends \App\Filament\Clusters\Sales\Resources\Services\ServiceResource
{
    public static function getEloquentQuery(): Builder
    {
        return app(CompanyAuthorization::class)->scopeQuery(parent::getEloquentQuery()->where('company_id', Filament::getTenant()->id),
            Filament::auth()->user(), Filament::getTenant(), 'services.view');
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('service_code')->label('Código')->searchable(),
            TextColumn::make('name')->label('Serviço')->searchable()->sortable(),
            TextColumn::make('price')->label('Preço')->money('BRL'),
            IconColumn::make('is_active')->label('Ativo')->boolean(),
        ])->filters([TernaryFilter::make('is_active')->label('Ativo')])
            ->recordActions([EditAction::make()])->defaultSort('name');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListServices::route('/'), 'create' => Pages\CreateService::route('/create'), 'edit' => Pages\EditService::route('/{record}/edit')];
    }
}
