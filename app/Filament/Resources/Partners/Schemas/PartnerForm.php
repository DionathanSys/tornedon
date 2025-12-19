<?php

namespace App\Filament\Resources\Partners\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class PartnerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required(),
                TextInput::make('type')
                    ->required(),
                TextInput::make('document_type')
                    ->required(),
                TextInput::make('document_number')
                    ->required(),
                Toggle::make('is_active')
                    ->required(),
                TextInput::make('state_tax_id'),
                TextInput::make('state_tax_indicator'),
                TextInput::make('municipal_tax_id'),
                TextInput::make('created_by')
                    ->numeric(),
                TextInput::make('updated_by')
                    ->numeric(),
            ]);
    }
}
