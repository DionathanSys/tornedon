<?php

namespace App\Filament\Bpo\Resources;

use App\Filament\Bpo\Resources\BankStatementImports\LinesRelationManager;
use App\Filament\Bpo\Resources\BankStatementImports\Pages;

class BankStatementImportResource extends \App\Filament\Clusters\Financial\Resources\BankStatementImports\BankStatementImportResource
{
    protected static ?string $cluster = null;

    protected static ?string $pluralModelLabel = 'Conciliação bancária';

    public static function getRelations(): array
    {
        return [LinesRelationManager::class];
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListImports::route('/'), 'view' => Pages\ViewImport::route('/{record}')];
    }
}
