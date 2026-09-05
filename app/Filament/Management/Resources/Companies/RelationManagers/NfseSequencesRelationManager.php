<?php

namespace App\Filament\Management\Resources\Companies\RelationManagers;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;

class NfseSequencesRelationManager extends SequenceRelationManager
{
    protected static string $relationship = 'nfseSequences';

    protected static bool $allowMultiple = true;

    protected static ?string $title = 'NFS-e';

    protected static ?string $modelLabel = 'Sequência de NFS-e';

    protected static ?string $pluralModelLabel = 'Sequências de NFS-e';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('serie')
                ->label('Série')
                ->required()
                ->maxLength(5)
                ->disabledOn('edit'),
            TextInput::make('last_number')
                ->label('Último número utilizado')
                ->numeric()
                ->integer()
                ->minValue(0)
                ->required(),
        ]);
    }

    /**
     * @return array<int, TextColumn>
     */
    protected static function getSequenceColumns(): array
    {
        return [
            TextColumn::make('serie')
                ->label('Série')
                ->badge()
                ->sortable(),
            static::lastNumberColumn(),
            static::nextNumberColumn(),
            static::updatedAtColumn(),
        ];
    }
}
