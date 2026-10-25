<?php

namespace App\Filament\Bpo\Resources;

use App\Filament\Bpo\Resources\AccountReceivables\Pages;
use App\Filament\Clusters\Financial\RelationManagers\AccountAttachmentsRelationManager;
use App\Filament\Clusters\Financial\Resources\AccountReceivables\RelationManagers\InstallmentsRelationManager;
use App\Filament\Clusters\Financial\Resources\AccountReceivables\RelationManagers\PaymentsRelationManager;
use App\Models\AccountReceivable;

class AccountReceivableResource extends FinancialTitleResource
{
    protected static ?string $model = AccountReceivable::class;

    public static string $module = 'account_receivables';

    protected static ?string $modelLabel = 'conta a receber';

    protected static ?string $pluralModelLabel = 'Contas a receber';

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
