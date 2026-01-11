<?php

namespace App\Filament\Clusters\Partners\Resources\CompanyPartners\Schemas;

use App\Enum;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class CompanyPartnerInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('company.name')
                    ->label('Empresa'),
                TextEntry::make('partner.name')
                    ->label('Parceiro'),
                TextEntry::make('type')
                    ->label('Tipo Parceiro')
                    ->formatStateUsing(function ($state) {
                        return collect($state)
                            ->map(fn($value) => Enum\Partner\Type::from($value)->description())
                            ->implode(', ');
                    })
                    ->badge(),
                TextEntry::make('invoice_threshold')
                    ->label('Vlr. Mín Fatura')
                    ->money('BRL')
                    ->placeholder('-'),
                TextEntry::make('created_at')
                    ->label('Criado a')
                    ->since()
                    ->placeholder('-'),
                TextEntry::make('updated_at')
                    ->label('Editado a')
                    ->since()
                    ->placeholder('-'),
                IconEntry::make('is_active')
                    ->label('Ativo')
                    ->inlineLabel(false)
                    ->boolean(),
            ]);
    }
}
