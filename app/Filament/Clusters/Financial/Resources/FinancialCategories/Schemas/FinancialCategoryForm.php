<?php

namespace App\Filament\Clusters\Financial\Resources\FinancialCategories\Schemas;

use App\Models\FinancialCategory;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class FinancialCategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns([
                'sm' => 1,
                'md' => 4,
                'lg' => 8,
            ])
            ->components([
                Section::make('Categoria Financeira')
                    ->columns([
                        'sm' => 1,
                        'md' => 6,
                        'lg' => 12,
                    ])
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('name')
                            ->label('Nome')
                            ->required()
                            ->maxLength(255)
                            ->columnSpan(['md' => 3, 'lg' => 5]),
                        Select::make('parent_id')
                            ->label('Categoria Pai')
                            ->options(fn (): array => FinancialCategory::query()
                                ->where('company_id', Filament::getTenant()->id)
                                ->orderBy('name')
                                ->get()
                                ->mapWithKeys(fn (FinancialCategory $category) => [$category->id => $category->full_name])
                                ->toArray())
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->columnSpan(['md' => 2, 'lg' => 5])
                            ->placeholder('Raiz'),
                        TextInput::make('sort_order')
                            ->label('Ordem')
                            ->numeric()
                            ->default(0)
                            ->columnSpan(['md' => 1, 'lg' => 2]),
                        Toggle::make('is_active')
                            ->label('Ativa')
                            ->inline(false)
                            ->default(true)
                            ->columnSpan(['md' => 2, 'lg' => 4]),
                        Toggle::make('allow_payable')
                            ->label('Usar em Despesas')
                            ->inline(false)
                            ->default(true)
                            ->columnSpan(['md' => 2, 'lg' => 4]),
                        Toggle::make('allow_receivable')
                            ->label('Usar em Receitas')
                            ->inline(false)
                            ->default(false)
                            ->columnSpan(['md' => 2, 'lg' => 4]),
                        Toggle::make('allow_cash_movement')
                            ->label('Usar em Transações')
                            ->inline(false)
                            ->default(true)
                            ->columnSpan(['md' => 2, 'lg' => 4]),
                        Textarea::make('description')
                            ->label('Descrição')
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),
                Hidden::make('company_id'),
                Hidden::make('created_by'),
                Hidden::make('updated_by'),
            ]);
    }
}
