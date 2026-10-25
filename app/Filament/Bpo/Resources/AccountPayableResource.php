<?php

namespace App\Filament\Bpo\Resources;

use App\Filament\Bpo\Resources\AccountPayables\Pages;
use App\Filament\Clusters\Financial\RelationManagers\AccountAttachmentsRelationManager;
use App\Filament\Clusters\Financial\Resources\AccountPayables\RelationManagers\InstallmentsRelationManager;
use App\Filament\Clusters\Financial\Resources\AccountPayables\RelationManagers\PaymentsRelationManager;
use App\Models\AccountPayable;

class AccountPayableResource extends FinancialTitleResource
{
    protected static ?string $model = AccountPayable::class;

    public static string $module = 'account_payables';

    protected static ?string $modelLabel = 'conta a pagar';

    protected static ?string $pluralModelLabel = 'Contas a pagar';

    public static function getRelations(): array
    {
        return [
            InstallmentsRelationManager::class,
            PaymentsRelationManager::class,
            AccountAttachmentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListAccounts::route('/'), 'create' => Pages\CreateAccount::route('/create'), 'view' => Pages\ViewAccount::route('/{record}'), 'edit' => Pages\EditAccount::route('/{record}/edit')];
    }
}
