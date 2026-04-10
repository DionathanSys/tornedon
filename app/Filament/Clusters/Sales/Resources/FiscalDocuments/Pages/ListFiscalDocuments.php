<?php

namespace App\Filament\Clusters\Sales\Resources\FiscalDocuments\Pages;

use App\Enum\FiscalDocument\NfeStatus;
use App\Filament\Clusters\Sales\Resources\FiscalDocuments\FiscalDocumentResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListFiscalDocuments extends ListRecords
{
    protected static string $resource = FiscalDocumentResource::class;

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('Todas')
                ->badgeColor('gray'),
            NfeStatus::PENDING->value => Tab::make('Pendente')
                ->modifyQueryUsing(fn(Builder $query): Builder => static::applyFiscalStatusFilter($query, NfeStatus::PENDING))
                ->badge(static::applyFiscalStatusFilter(static::getResource()::getEloquentQuery(), NfeStatus::PENDING)->count())
                ->badgeColor(NfeStatus::PENDING->color()),
            NfeStatus::IN_PROCESSING->value => Tab::make('Em Processamento')
                ->modifyQueryUsing(fn(Builder $query): Builder => static::applyFiscalStatusFilter($query, NfeStatus::IN_PROCESSING))
                ->badge(static::applyFiscalStatusFilter(static::getResource()::getEloquentQuery(), NfeStatus::IN_PROCESSING)->count())
                ->badgeColor(NfeStatus::IN_PROCESSING->color()),
            NfeStatus::AUTHORIZED->value => Tab::make('Autorizada')
                ->modifyQueryUsing(fn(Builder $query): Builder => static::applyFiscalStatusFilter($query, NfeStatus::AUTHORIZED))
                ->badge(static::applyFiscalStatusFilter(static::getResource()::getEloquentQuery(), NfeStatus::AUTHORIZED)->count())
                ->badgeColor(NfeStatus::AUTHORIZED->color()),
            NfeStatus::REJECTED->value => Tab::make('Rejeitada')
                ->modifyQueryUsing(fn(Builder $query): Builder => static::applyFiscalStatusFilter($query, NfeStatus::REJECTED))
                ->badge(static::applyFiscalStatusFilter(static::getResource()::getEloquentQuery(), NfeStatus::REJECTED)->count())
                ->badgeColor(NfeStatus::REJECTED->color()),
            NfeStatus::CANCELED->value => Tab::make('Cancelada')
                ->modifyQueryUsing(fn(Builder $query): Builder => static::applyFiscalStatusFilter($query, NfeStatus::CANCELED))
                ->badge(static::applyFiscalStatusFilter(static::getResource()::getEloquentQuery(), NfeStatus::CANCELED)->count())
                ->badgeColor(NfeStatus::CANCELED->color()),
        ];
    }

    public function getDefaultActiveTab(): string | int | null
    {
        return NfeStatus::PENDING->value;
    }

    protected static function applyFiscalStatusFilter(Builder $query, NfeStatus $status): Builder
    {
        return $query->where(function (Builder $query) use ($status): void {
            $query
                ->where('nfe_status', $status->value)
                ->orWhere('nfse_status', $status->value);

            if ($status === NfeStatus::PENDING) {
                $query->orWhere(function (Builder $q): void {
                    $q->whereNull('nfe_status')
                        ->whereNull('nfse_status');
                });
            }
        });
    }

    protected function getHeaderActions(): array
    {
        return [

        ];
    }
}
