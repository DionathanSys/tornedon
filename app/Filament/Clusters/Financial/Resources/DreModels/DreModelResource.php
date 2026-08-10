<?php

namespace App\Filament\Clusters\Financial\Resources\DreModels;

use App\Filament\Clusters\Financial\Resources\DreModels\Pages\CreateDreModel;
use App\Filament\Clusters\Financial\Resources\DreModels\Pages\EditDreModel;
use App\Filament\Clusters\Financial\Resources\DreModels\Pages\ListDreModels;
use App\Filament\Clusters\Financial\Resources\DreModels\RelationManagers\LinesRelationManager;
use App\Models\DreModel;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class DreModelResource extends Resource
{
    protected static ?string $model = DreModel::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBarSquare;

    protected static string|UnitEnum|null $navigationGroup = 'Financeiro';

    protected static ?string $modelLabel = 'Modelo de DRE';

    protected static ?string $pluralModelLabel = 'Modelos de DRE';

    protected static ?int $navigationSort = 14;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Modelo DRE')
                ->columns(['sm' => 1, 'md' => 4, 'lg' => 8])
                ->schema([
                    TextInput::make('name')
                        ->label('Nome')
                        ->required()
                        ->maxLength(255)
                        ->columnSpan(['md' => 2, 'lg' => 4]),
                    TextInput::make('template_key')
                        ->label('Chave do Template')
                        ->maxLength(255)
                        ->helperText('Modelos consolidados precisam compartilhar a mesma chave e estrutura.')
                        ->columnSpan(['md' => 2, 'lg' => 4]),
                    TextInput::make('template_version')
                        ->label('Versão')
                        ->numeric()
                        ->default(1)
                        ->columnSpan(['md' => 1, 'lg' => 2]),
                    Toggle::make('is_default')
                        ->label('Padrão')
                        ->inline(false)
                        ->default(false)
                        ->columnSpan(['md' => 1, 'lg' => 2]),
                    Toggle::make('is_template_locked')
                        ->label('Bloquear Estrutura')
                        ->inline(false)
                        ->default(false)
                        ->columnSpan(['md' => 1, 'lg' => 2]),
                    Toggle::make('is_active')
                        ->label('Ativo')
                        ->inline(false)
                        ->default(true)
                        ->columnSpan(['md' => 1, 'lg' => 2]),
                    Textarea::make('description')
                        ->label('Descrição')
                        ->rows(3)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Modelo')->searchable()->sortable(),
                TextColumn::make('template_key')->label('Template')->placeholder('-')->toggleable(),
                TextColumn::make('template_version')->label('Versão')->alignCenter(),
                TextColumn::make('structure_hash')->label('Hash')->limit(12)->placeholder('-')->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_default')->label('Padrão')->boolean()->alignCenter(),
                IconColumn::make('is_active')->label('Ativo')->boolean()->alignCenter(),
            ])
            ->defaultSort('name');
    }

    public static function getRelations(): array
    {
        return [
            LinesRelationManager::class,
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('company_id', Filament::getTenant()->id);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDreModels::route('/'),
            'create' => CreateDreModel::route('/create'),
            'edit' => EditDreModel::route('/{record}/edit'),
        ];
    }
}
