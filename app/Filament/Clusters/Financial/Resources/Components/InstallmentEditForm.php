<?php

namespace App\Filament\Clusters\Financial\Resources\Components;

use App\Models\CostCenter;
use App\Models\ResultCenter;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Leandrocfe\FilamentPtbrFormFields\Money;

final class InstallmentEditForm
{
    public static function components(string $scope): array
    {
        return [
            Section::make('Valor e datas')
                ->description('O valor base não inclui juros, multa ou desconto. O saldo e o total da conta serão recalculados ao salvar.')
                ->compact()
                ->columnSpanFull()
                ->columns(['default' => 1, 'md' => 3])
                ->schema([
                    Money::make('original_amount')
                        ->label('Valor da parcela')
                        ->formatStateUsing(fn ($state) => number_format((float) $state, 2, ',', '.'))
                        ->required(),
                    DatePicker::make('due_date')
                        ->label('Vencimento')
                        ->displayFormat('d/m/Y')
                        ->required(),
                    DatePicker::make('competence_date')
                        ->label('Competência')
                        ->displayFormat('d/m/Y')
                        ->helperText('Data econômica usada nos relatórios.'),
                ]),
            Section::make('Classificação financeira')
                ->compact()
                ->columnSpanFull()
                ->columns(['default' => 1, 'md' => 2])
                ->schema([
                    SelectFinancialCategory::make('financial_category_id', $scope)
                        ->label('Categoria financeira')
                        ->placeholder('Selecione uma categoria')
                        ->columnSpanFull(),
                    Select::make('cost_center_id')
                        ->label('Centro de custo')
                        ->options(fn (): array => CostCenter::optionsForCompany(Filament::getTenant()->id))
                        ->searchable()
                        ->preload()
                        ->native(false),
                    Select::make('result_center_id')
                        ->label('Centro de resultado')
                        ->options(fn (): array => ResultCenter::optionsForCompany(Filament::getTenant()->id))
                        ->searchable()
                        ->preload()
                        ->native(false),
                ]),
            Section::make('Detalhes')
                ->compact()
                ->columnSpanFull()
                ->columns(['default' => 1, 'md' => 2])
                ->schema([
                    Textarea::make('description')
                        ->label('Descrição da parcela')
                        ->rows(3)
                        ->maxLength(255),
                    Textarea::make('notes')
                        ->label('Observações')
                        ->rows(3),
                ]),
        ];
    }
}
