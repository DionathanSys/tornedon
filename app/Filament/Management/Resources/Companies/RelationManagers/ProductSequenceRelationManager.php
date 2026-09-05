<?php

namespace App\Filament\Management\Resources\Companies\RelationManagers;

class ProductSequenceRelationManager extends SequenceRelationManager
{
    protected static string $relationship = 'productSequence';

    protected static ?string $title = 'Produtos';

    protected static ?string $modelLabel = 'Sequência de produtos';

    protected static ?string $pluralModelLabel = 'Sequência de produtos';
}
