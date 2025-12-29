<?php

namespace App\Filament\Clusters\Partners\Resources\CompanyPartners\Schemas;

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
                    ->badge(),
                TextEntry::make('invoice_threshold')
                    ->label('Vlr. Mín Fatura')
                    ->money('BRL')
                    ->placeholder('-'),
                TextEntry::make('created_at')
                    ->label('Criado em')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('-'),
                TextEntry::make('updated_at')
                    ->label('Editado em')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('-'),
                IconEntry::make('is_active')
                    ->label('Ativo')
                    ->inlineLabel(false)
                    ->boolean(),
            ]);
    }
}
