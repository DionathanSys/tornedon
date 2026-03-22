<?php

namespace App\Filament\Mobile\Resources\MobileServices\Tables;

use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Layout\Grid;
use Filament\Tables\Columns\Layout\Panel;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MobileServicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->contentGrid([
                'md' => 2,
                'xl' => 3,
            ])
            ->columns([
                Split::make([
                    Stack::make([
                        TextColumn::make('name')
                            ->label('Nome')
                            ->searchable(query: function (Builder $query, string $search): Builder {
                                $searchTerm = "%{$search}%";

                                return $query->where(function (Builder $query) use ($searchTerm): void {
                                    $query
                                        ->where('name', 'like', $searchTerm)
                                        ->orWhere('description', 'like', $searchTerm)
                                        ->orWhere('nbs_code', 'like', $searchTerm)
                                        ->orWhere('cnae_code', 'like', $searchTerm);
                                });
                            })
                            ->sortable()
                            ->weight('bold')
                            ->lineClamp(2),
                        TextColumn::make('category')
                            ->label('Categoria')
                            ->placeholder('Sem categoria')
                            ->badge()
                            ->color('info'),
                        TextColumn::make('description')
                            ->label("Descri\u{00E7}\u{00E3}o")
                            ->placeholder('Sem descricao')
                            ->limit(120)
                            ->lineClamp(2)
                            ->color('gray'),
                    ]),
                    Stack::make([
                        TextColumn::make('price')
                            ->label("Pre\u{00E7}o")
                            ->money('BRL')
                            ->sortable()
                            ->weight('bold'),
                        TextColumn::make('is_active')
                            ->label('Status')
                            ->state(fn (bool $state): string => $state ? 'Ativo' : 'Inativo')
                            ->badge()
                            ->color(fn (bool $state): string => $state ? 'success' : 'gray')
                            ->sortable(),
                        TextColumn::make('requires_approval')
                            ->label("Aprova\u{00E7}\u{00E3}o")
                            ->state(fn (bool $state): string => $state ? "Requer aprova\u{00E7}\u{00E3}o" : 'Fluxo livre')
                            ->badge()
                            ->color(fn (bool $state): string => $state ? 'warning' : 'gray')
                            ->sortable(),
                    ])
                        ->alignment(Alignment::End)
                        ->space(1),
                ])->from('md'),
                Panel::make([
                    Grid::make([
                        'default' => 1,
                        'xl' => 2,
                    ])->schema([
                        TextColumn::make('nbs_code')
                            ->label('NBS')
                            ->placeholder('-'),
                        TextColumn::make('cnae_code')
                            ->label('CNAE')
                            ->placeholder('-'),
                        TextColumn::make('created_at')
                            ->label('Criado em')
                            ->dateTime('d/m/Y H:i')
                            ->sortable(),
                        TextColumn::make('updated_at')
                            ->label('Atualizado em')
                            ->dateTime('d/m/Y H:i')
                            ->sortable(),
                    ]),
                ])->collapsible(),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Ativo')
                    ->boolean()
                    ->trueLabel('Apenas ativos')
                    ->falseLabel('Apenas inativos')
                    ->native(false)
                    ->default(true),
                SelectFilter::make('category')
                    ->label('Categoria')
                    ->multiple()
                    ->native(false),
                TernaryFilter::make('requires_approval')
                    ->label("Requer aprova\u{00E7}\u{00E3}o")
                    ->boolean()
                    ->trueLabel("Apenas que requerem aprova\u{00E7}\u{00E3}o")
                    ->falseLabel("Apenas que n\u{00E3}o requerem aprova\u{00E7}\u{00E3}o")
                    ->native(false),
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make()
                    ->label('Editar')
                    ->button(),
            ])
            ->recordActionsPosition(RecordActionsPosition::AfterContent)
            ->toolbarActions([
                CreateAction::make()
                    ->icon(Heroicon::Plus)
                    ->label("Servi\u{00E7}o")
                    ->size(Size::Small),
            ])
            ->defaultSort('created_at', 'desc')
            ->searchPlaceholder("Buscar por nome, descri\u{00E7}\u{00E3}o, NBS ou CNAE...");
    }
}
