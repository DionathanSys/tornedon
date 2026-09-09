<?php

namespace App\Filament\Management\Resources\Companies\RelationManagers;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;

class NfeSequencesRelationManager extends SequenceRelationManager
{
    protected static string $relationship = 'nfeSequences';

    protected static bool $allowMultiple = true;

    protected static ?string $title = 'NF-e';

    protected static ?string $modelLabel = 'Sequência de NF-e';

    protected static ?string $pluralModelLabel = 'Sequências de NF-e';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('serie')
                ->label('Série')
                ->required()
                ->maxLength(3)
                ->disabledOn('edit'),
            TextInput::make('operation_nature')
                ->label('Natureza da operação')
                ->default('')
                ->maxLength(100),
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
            TextColumn::make('operation_nature')
                ->label('Natureza da operação')
                ->placeholder('-')
                ->searchable(),
            static::lastNumberColumn(),
            static::nextNumberColumn(),
            static::updatedAtColumn(),
        ];
    }
}
