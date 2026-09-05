<?php

namespace App\Filament\Management\Resources\Companies\RelationManagers;

class ProductionOrderSequenceRelationManager extends SequenceRelationManager
{
    protected static string $relationship = 'productionOrderSequence';

    protected static ?string $title = 'Ordens de produção';

    protected static ?string $modelLabel = 'Sequência de ordens de produção';

    protected static ?string $pluralModelLabel = 'Sequência de ordens de produção';
}
