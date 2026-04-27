<?php

namespace App\Filament\Clusters\Settings\Resources\FiscalRules\Schemas;

use App\Enum\FiscalDocument\OperationNature;
use App\Enum\Tax\TaxRegime;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class FiscalRuleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('company_id')
                    ->relationship('company', 'name')
                    ->required(),
                Select::make('fiscal_profile_id')
                    ->relationship('fiscalProfile', 'id')
                    ->required(),
                Select::make('operation_nature')
                    ->options(OperationNature::class)
                    ->required(),
                Select::make('tax_regime')
                    ->options(TaxRegime::class)
                    ->required(),
                Toggle::make('is_interestadual')
                    ->required(),
                TextInput::make('product_origin'),
                Toggle::make('has_st'),
                TextInput::make('ncm_prefix'),
                TextInput::make('recipient_type'),
                Toggle::make('is_final_consumer'),
                TextInput::make('cfop')
                    ->required(),
                TextInput::make('cst_icms'),
                TextInput::make('csosn'),
                TextInput::make('cst_pis'),
                TextInput::make('cst_cofins'),
                TextInput::make('cst_ipi'),
                TextInput::make('aliquota_icms')
                    ->numeric(),
                TextInput::make('aliquota_pis')
                    ->numeric(),
                TextInput::make('aliquota_cofins')
                    ->numeric(),
                TextInput::make('aliquota_ipi')
                    ->numeric(),
                TextInput::make('priority')
                    ->required()
                    ->numeric()
                    ->default(0),
                Toggle::make('is_active')
                    ->required(),
                TextInput::make('description'),
                DatePicker::make('valid_from'),
                DatePicker::make('valid_until'),
                TextInput::make('created_by')
                    ->numeric(),
                TextInput::make('updated_by')
                    ->numeric(),
            ]);
    }
}
