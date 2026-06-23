<?php

namespace App\Filament\Clusters\Sales\Resources\Requisitions\Pages\Actions;

use App\Enum\WarrantyClaim\CoverageType;
use App\Enum\WarrantyClaim\Responsibility;
use App\Filament\Clusters\Sales\Resources\Components\SelectPartner;
use App\Filament\Clusters\Sales\Resources\WarrantyClaims\WarrantyClaimResource;
use App\Models\Requisition;
use App\Notification\NotifyService as notify;
use App\Services\WarrantyClaim\WarrantyClaimService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
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
            ->modalHeading('Criar garantia da peça vendida')
            ->visible(fn (Requisition $record): bool => $record->items()->exists())
            ->columns(2)
            ->schema([
                Select::make('product_id')
                    ->label('Produto')
                    ->options(function (Requisition $record): array {
                        $record->loadMissing('items.product');

                        return $record->items
                            ->filter(fn ($item): bool => $item->product !== null)
                            ->mapWithKeys(fn ($item) => [
                                $item->product_id => trim(($item->product->product_code ? $item->product->product_code.' - ' : '').$item->product->name.' (Qtd: '.number_format((float) $item->quantity, 3, ',', '.').')'),
                            ])
                            ->toArray();
                    })
                    ->default(fn (Requisition $record): ?int => $record->items()->count() === 1 ? $record->items()->value('product_id') : null)
                    ->required()
                    ->native(false),
                SelectPartner::make('supplier_id', 'supplier')
                    ->label('Fornecedor'),
                Select::make('coverage_type')
                    ->label('Cobertura')
                    ->options(CoverageType::toSelectArray())
                    ->native(false)
                    ->default(CoverageType::PARTS->value)
                    ->required(),
                Select::make('responsibility')
                    ->label('Responsabilidade')
                    ->options(Responsibility::toSelectArray())
                    ->native(false)
                    ->default(Responsibility::SUPPLIER->value)
                    ->required(),
                Toggle::make('advanced_replacement')
                    ->label('Troca antecipada')
                    ->inline(false)
                    ->default(false),
                DatePicker::make('expires_at')
                    ->label('Garantia válida até')
                    ->displayFormat('d/m/Y'),
                Textarea::make('customer_issue_description')
                    ->label('Problema informado pelo cliente')
                    ->required()
                    ->rows(4),
            ])
            ->action(function (Requisition $record, array $data): void {
                $service = app(WarrantyClaimService::class);
                $claim = $service->openFromRequisition($record, $data, Auth::id());

                if ($service->hasError() || $claim === null) {
                    Log::warning('OpenWarrantyClaimAction: falha ao abrir garantia da requisição', [
                        'metodo' => __METHOD__.'@'.__LINE__,
                        'requisition_id' => $record->id,
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
