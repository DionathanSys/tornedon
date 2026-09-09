<?php

namespace App\Filament\Management\Resources\Companies\RelationManagers;

class ProductionRequestSequenceRelationManager extends SequenceRelationManager
{
    protected static string $relationship = 'productionRequestSequence';

    protected static ?string $title = 'Solicitações de produção';

    protected static ?string $modelLabel = 'Sequência de solicitações de produção';

    protected static ?string $pluralModelLabel = 'Sequência de solicitações de produção';
}
