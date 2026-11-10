<?php

namespace App\Filament\Clusters\Inventory\Resources\Products\RelationManagers;

use App\Services\Access\UserAccessService;
use Filament\Facades\Filament;
use Filament\Resources\RelationManagers\RelationManager;
use Illuminate\Database\Eloquent\Model;

abstract class InventoryRelationManager extends RelationManager
{
    protected static string $permission = 'stock_movements.view';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Filament::getTenant() && (int) $ownerRecord->company_id === (int) Filament::getTenant()->id
            && app(UserAccessService::class)->allows(Filament::auth()->user(), Filament::getTenant(), static::$permission);
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}
