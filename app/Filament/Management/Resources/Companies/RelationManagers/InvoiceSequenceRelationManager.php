<?php

namespace App\Filament\Management\Resources\Companies\RelationManagers;

class InvoiceSequenceRelationManager extends SequenceRelationManager
{
    protected static string $relationship = 'invoiceSequence';

    protected static ?string $title = 'Faturas';

    protected static ?string $modelLabel = 'Sequência de faturas';

    protected static ?string $pluralModelLabel = 'Sequência de faturas';
}
