<?php

namespace App\Filament\Bpo\Resources;

use App\Filament\Bpo\Resources\AccountReceivables\Pages;
use App\Filament\Bpo\Resources\AccountReceivables\RelationManagers\PaymentsRelationManager;
use App\Filament\Clusters\Financial\RelationManagers\AccountAttachmentsRelationManager;
use App\Filament\Clusters\Financial\Resources\AccountReceivables\RelationManagers\InstallmentsRelationManager;
use App\Models\AccountReceivable;
use Illuminate\Database\Eloquent\Model;

class AccountReceivableResource extends FinancialTitleResource
{
    protected static ?string $model = AccountReceivable::class;

    public static string $module = 'account_receivables';

    protected static ?string $modelLabel = 'conta a receber';

    protected static ?string $pluralModelLabel = 'Contas a receber';

    public static function getUrl(?string $name = null, array $parameters = [], bool $isAbsolute = true, ?string $panel = null, ?Model $tenant = null, bool $shouldGuessMissingParameters = false, ?string $configuration = null): string
    {
        if ($name === null || $name === 'index') {
            return AccountReceivableInstallmentResource::getUrl('index', $parameters, $isAbsolute, $panel, $tenant, $shouldGuessMissingParameters, $configuration);
        }

        return parent::getUrl($name, $parameters, $isAbsolute, $panel, $tenant, $shouldGuessMissingParameters, $configuration);
    }

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
