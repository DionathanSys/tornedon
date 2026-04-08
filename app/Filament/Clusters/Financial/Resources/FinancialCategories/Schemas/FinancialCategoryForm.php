<?php

namespace App\Filament\Clusters\Financial\Resources\FinancialCategories\Schemas;

use App\Models\FinancialCategory;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
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
                        'md' => 4,
                        'lg' => 8,
                    ])
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('name')
                            ->label('Nome')
                            ->required()
                            ->maxLength(255)
                            ->columnSpan(['md' => 2, 'lg' => 4]),
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
                            ->columnSpan(['md' => 2, 'lg' => 4])
                            ->placeholder('Raiz'),
                        TextInput::make('sort_order')
                            ->label('Ordem')
                            ->numeric()
                            ->default(0)
                            ->columnSpan(['md' => 1, 'lg' => 2]),
                        Checkbox::make('is_active')
                            ->label('Ativa')
                            ->default(true)
                            ->columnSpan(['md' => 1, 'lg' => 2]),
                        Checkbox::make('allow_payable')
                            ->label('Usar em Despesas')
                            ->default(true)
                            ->columnSpan(['md' => 1, 'lg' => 2]),
                        Checkbox::make('allow_receivable')
                            ->label('Usar em Receitas')
                            ->default(false)
                            ->columnSpan(['md' => 1, 'lg' => 2]),
                        Checkbox::make('allow_cash_movement')
                            ->label('Usar em Transacoes')
                            ->default(true)
                            ->columnSpan(['md' => 1, 'lg' => 2]),
                        Textarea::make('description')
                            ->label('Descricao')
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),
                Hidden::make('company_id'),
                Hidden::make('created_by'),
                Hidden::make('updated_by'),
            ]);
    }
}
