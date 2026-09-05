<?php

namespace App\Filament\Management\Resources\Companies\RelationManagers;

class ServiceOrderSequenceRelationManager extends SequenceRelationManager
{
    protected static string $relationship = 'serviceOrderSequence';

    protected static ?string $title = 'Ordens de serviço';

    protected static ?string $modelLabel = 'Sequência de ordens de serviço';

    protected static ?string $pluralModelLabel = 'Sequência de ordens de serviço';
}
