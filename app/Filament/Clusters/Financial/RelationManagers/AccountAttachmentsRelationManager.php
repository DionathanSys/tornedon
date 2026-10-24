<?php

namespace App\Filament\Clusters\Financial\RelationManagers;

use App\Filament\RelationManagers\AttachmentsRelationManager;

class AccountAttachmentsRelationManager extends AttachmentsRelationManager
{
    public function isReadOnly(): bool
    {
        return false;
    }
}
