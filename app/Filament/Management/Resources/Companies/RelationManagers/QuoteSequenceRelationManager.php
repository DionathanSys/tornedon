<?php

namespace App\Filament\Management\Resources\Companies\RelationManagers;

class QuoteSequenceRelationManager extends SequenceRelationManager
{
    protected static string $relationship = 'quoteSequence';

    protected static ?string $title = 'Orçamentos';

    protected static ?string $modelLabel = 'Sequência de orçamentos';

    protected static ?string $pluralModelLabel = 'Sequência de orçamentos';
}
