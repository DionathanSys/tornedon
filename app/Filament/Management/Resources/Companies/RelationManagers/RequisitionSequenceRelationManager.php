<?php

namespace App\Filament\Management\Resources\Companies\RelationManagers;

class RequisitionSequenceRelationManager extends SequenceRelationManager
{
    protected static string $relationship = 'requisitionSequence';

    protected static ?string $title = 'Requisições';

    protected static ?string $modelLabel = 'Sequência de requisições';

    protected static ?string $pluralModelLabel = 'Sequência de requisições';
}
