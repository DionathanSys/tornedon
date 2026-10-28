<?php

namespace App\Filament\Concerns;

use App\Models\HrEntry;
use App\Models\HrPerson;
use App\Services\Hr\HrAccess;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

trait AuthorizesHrResource
{
    public static function allowed(string $action, ?Model $record = null): bool
    {
        $user = Filament::auth()->user();
        $company = Filament::getTenant();

        return $user && $company && app(HrAccess::class)->allows($user, $company, static::$hrModule.'.'.$action, $record);
    }

    public static function canViewAny(): bool
    {
        return static::allowed('view');
    }

    public static function canView(Model $record): bool
    {
        return static::allowed('view', $record);
    }

    public static function canCreate(): bool
    {
        return static::$hrModule !== 'hr_receipts' && static::allowed('create');
    }

    public static function canEdit(Model $record): bool
    {
        return static::allowed('update', $record) && (! $record instanceof HrEntry || $record->status === 'draft');
    }

    public static function canDelete(Model $record): bool
    {
        return static::$hrModule !== 'hr_receipts' && static::allowed('delete', $record)
            && (! $record instanceof HrEntry || $record->status === 'draft')
            && (! $record instanceof HrPerson || ! $record->entries()->withoutGlobalScopes()->exists());
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('company_id', Filament::getTenant()?->id);
    }
}
