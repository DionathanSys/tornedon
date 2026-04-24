<?php

namespace App\Filament\Tables;

use App\Models\Service;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ServiceTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Service::query())
            ->columns([
                TextColumn::make('service_code')
                    ->label('Código')
                    ->width('1%')
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Serviço')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('price')
                    ->label('Preço')
                    ->money('BRL')
                    ->sortable(),
                TextColumn::make('category.name')
                    ->label('Categoria')
                    ->placeholder('Sem categoria')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('nbs_code')
                    ->label('Código NBS')
                    ->placeholder('Sem Código NBS'),
                TextColumn::make('municipal_tax_code')
                    ->label('Código ISS')
                    ->placeholder('Sem Código ISS'),
                IconColumn::make('is_active')
                    ->label('Ativo')
                    ->boolean(),
                IconColumn::make('accept_customer_discount')
                    ->label('Permite Desconto Cliente')
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('is_active')
                    ->label('Ativo')
                    ->options([
                        '1' => 'Sim',
                        '0' => 'Não',
                    ])
                    ->default('1'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Serviço')
                    ->icon(Heroicon::Plus)
                    ->size(Size::Small),
            ])
            ->recordActions([
                Action::make('teste')
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    //
                ]),
            ]);
    }
}
