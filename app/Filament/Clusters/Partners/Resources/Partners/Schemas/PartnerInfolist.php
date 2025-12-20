<?php

namespace App\Filament\Clusters\Partners\Resources\Partners\Schemas;

use App\Models\Partner;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class PartnerInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                IconEntry::make('is_active')
                    ->label('Ativo')
                    ->boolean(),
                TextEntry::make('name')
                    ->label('Nome')
                    ->columnStart(1),
                TextEntry::make('document_type')
                    ->label('Tipo de Doc.')
                    ->formatStateUsing(fn (string $state): string => strtoupper($state)),
                TextEntry::make('document_number')
                    ->label('Nº do Doc.'),
                TextEntry::make('state_tax_id')
                        ->label('Inscrição Estadual')
                    ->placeholder('-'),
                TextEntry::make('state_tax_indicator')
                    ->label('Indicador IE')
                    ->placeholder('-'),
                TextEntry::make('municipal_tax_id')
                    ->label('Inscrição Municipal')
                    ->placeholder('-'),
                TextEntry::make('created_at')
                    ->label('Criado em')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('-'),
                TextEntry::make('updated_at')
                    ->label('Atualizado em')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('-'),
                TextEntry::make('deleted_at')
                    ->label('Deletado em')
                    ->dateTime('d/m/Y H:i')
                    ->visible(fn (Partner $record): bool => $record->trashed()),
            ]);
    }
}
