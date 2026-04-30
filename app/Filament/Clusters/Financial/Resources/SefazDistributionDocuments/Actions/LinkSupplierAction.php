<?php

namespace App\Filament\Clusters\Financial\Resources\SefazDistributionDocuments\Actions;

use App\Models\Partner;
use App\Models\SefazDistributionDocument;
use App\Services\Fiscal\Sefaz\SefazDistributionDocumentService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;

class LinkSupplierAction
{
    public static function make(): Action
    {
        return Action::make('linkSupplier')
            ->label('Vincular fornecedor')
            ->icon('heroicon-o-user-plus')
            ->schema(fn(SefazDistributionDocument $record): array => [
                Select::make('partner_id')
                    ->label('Fornecedor')
                    ->required()
                    ->searchable()
                    ->options(
                        Partner::query()
                            ->whereHas('companies', fn($query) => $query->whereKey($record->company_id))
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all()
                    )
                    ->default($record->partner_id),
            ])
            ->action(function (SefazDistributionDocument $record, array $data): void {
                $partner = Partner::query()->findOrFail($data['partner_id']);
                app(SefazDistributionDocumentService::class)->updatePartnerLink($record, $partner, Auth::id());

                Notification::make()
                    ->title('Fornecedor vinculado')
                    ->success()
                    ->send();
            });
    }
}
