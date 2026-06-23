<?php

namespace App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions;

use App\Enum\WarrantyClaim\CoverageType;
use App\Enum\WarrantyClaim\Responsibility;
use App\Filament\Clusters\Sales\Resources\WarrantyClaims\WarrantyClaimResource;
use App\Models\ServiceOrder;
use App\Notification\NotifyService as notify;
use App\Services\WarrantyClaim\WarrantyClaimService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class OpenWarrantyClaimAction
{
    public static function make(): Action
    {
        return Action::make('openWarrantyClaim')
            ->label('Abrir garantia')
            ->icon(Heroicon::ShieldCheck)
            ->color('warning')
            ->modalHeading('Abrir garantia da OS')
            ->modalWidth(Width::FiveExtraLarge)
            ->form([
                Grid::make(3)
                    ->schema([
                        Select::make('coverage_type')
                            ->label('Cobertura')
                            ->options(CoverageType::toSelectArray())
                            ->native(false)
                            ->default(CoverageType::LABOR_AND_PARTS->value)
                            ->required(),
                        Select::make('responsibility')
                            ->label('Responsabilidade')
                            ->options(Responsibility::toSelectArray())
                            ->native(false)
                            ->default(Responsibility::COMPANY->value)
                            ->required(),
                        DatePicker::make('expires_at')
                            ->label('Garantia válida até')
                            ->displayFormat('d/m/Y')
                            ->default(fn (ServiceOrder $record): ?string => $record->warranty_expires_at?->toDateString()),
                    ]),
                Textarea::make('customer_issue_description')
                    ->label('Problema informado pelo cliente')
                    ->required()
                    ->rows(4),
            ])
            ->action(function (ServiceOrder $record, array $data): void {
                $service = app(WarrantyClaimService::class);
                $claim = $service->openFromServiceOrder($record, $data, Auth::id());

                if ($service->hasError() || $claim === null) {
                    Log::warning('OpenWarrantyClaimAction: falha ao abrir garantia da OS', [
                        'metodo' => __METHOD__.'@'.__LINE__,
                        'service_order_id' => $record->id,
                        'message' => $service->getMessage(),
                        'error_code' => $service->getErrorCode(),
                    ]);

                    notify::error(message: $service->getMessageUser(), errorCode: $service->getErrorCode());

                    return;
                }

                notify::success(message: 'Garantia criada com sucesso.');

                redirect(WarrantyClaimResource::getUrl('edit', ['record' => $claim]));
            });
    }
}
