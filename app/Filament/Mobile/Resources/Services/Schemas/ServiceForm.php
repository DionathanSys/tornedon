<?php

namespace App\Filament\Mobile\Resources\Services\Schemas;

use App\Enum\Tax\IssExigibility;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class ServiceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('service_code'),
                TextInput::make('name')
                    ->required(),
                Textarea::make('description')
                    ->columnSpanFull(),
                TextInput::make('price')
                    ->required()
                    ->numeric()
                    ->default(0.0)
                    ->prefix('$'),
                TextInput::make('min_sale_price')
                    ->numeric()
                    ->prefix('$')
                    ->helperText('O preco efetivo apos desconto nao pode ficar abaixo deste valor.'),
                TextInput::make('cost')
                    ->numeric()
                    ->default(0.0)
                    ->prefix('$'),
                TextInput::make('category'),
                Toggle::make('is_active')
                    ->required(),
                Toggle::make('requires_approval')
                    ->required(),
                Toggle::make('accept_customer_discount')
                    ->label('Aceita desconto do cliente')
                    ->helperText('Aplica automaticamente o desconto do cadastro do cliente quando o servico for inserido em OS ou Orcamento.')
                    ->default(false),
                TextInput::make('tax_classification'),
                TextInput::make('tax_rate')
                    ->numeric()
                    ->default(0.0),
                TextInput::make('nbs_code'),
                TextInput::make('cnae_code'),
                TextInput::make('ncm_code'),
                TextInput::make('cfop_code'),
                TextInput::make('origin_code')
                    ->default('07'),
                TextInput::make('unit_of_measure')
                    ->default('UN'),
                TextInput::make('municipal_tax_code'),
                Select::make('iss_exigibility')
                    ->options(IssExigibility::class),
                TextInput::make('additional_info'),
                TextInput::make('created_by')
                    ->numeric(),
                TextInput::make('updated_by')
                    ->numeric(),
                Select::make('company_id')
                    ->relationship('company', 'name')
                    ->required(),
            ]);
    }
}
