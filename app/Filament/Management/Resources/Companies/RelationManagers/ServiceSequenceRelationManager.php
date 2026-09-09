<?php

namespace App\Filament\Management\Resources\Companies\RelationManagers;

class ServiceSequenceRelationManager extends SequenceRelationManager
{
    protected static string $relationship = 'serviceSequence';

    protected static ?string $title = 'Serviços';

    protected static ?string $modelLabel = 'Sequência de serviços';

    protected static ?string $pluralModelLabel = 'Sequência de serviços';
}
