<?php

namespace App\Filament\Bpo\Resources;

use App\Filament\Bpo\Resources\AccountPayables\Pages;
use App\Filament\Bpo\Resources\AccountPayables\RelationManagers\PaymentsRelationManager;
use App\Filament\Clusters\Financial\RelationManagers\AccountAttachmentsRelationManager;
use App\Filament\Clusters\Financial\Resources\AccountPayables\RelationManagers\InstallmentsRelationManager;
use App\Models\AccountPayable;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class AccountPayableResource extends FinancialTitleResource
{
    protected static ?string $model = AccountPayable::class;

    public static string $module = 'account_payables';

    protected static ?string $modelLabel = 'conta a pagar';

    protected static ?string $pluralModelLabel = 'Contas a pagar';

    public static function getUrl(?string $name = null, array $parameters = [], bool $isAbsolute = true, ?string $panel = null, ?Model $tenant = null, bool $shouldGuessMissingParameters = false, ?string $configuration = null): string
    {
        if ($name === null || $name === 'index') {
            return AccountPayableInstallmentResource::getUrl('index', $parameters, $isAbsolute, $panel, $tenant, $shouldGuessMissingParameters, $configuration);
        }

        return parent::getUrl($name, $parameters, $isAbsolute, $panel, $tenant, $shouldGuessMissingParameters, $configuration);
    }

    public static function table(Table $table): Table
    {
        $table = parent::table($table);

        foreach ($table->getRecordActions() as $group) {
            if (! $group instanceof ActionGroup) {
                continue;
            }

            $group->icon(Heroicon::Bars3)->tooltip('Ações da conta a pagar')->iconButton();

            foreach ($group->getFlatActions() as $action) {
                $action->tooltip(fn (Action $action) => $action->getLabel());
            }
        }

        return $table;
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
