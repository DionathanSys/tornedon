<?php

namespace App\Filament\Clusters\Sales\Resources\ServiceOrders\RelationManagers\Actions;

use App\Models\ServiceOrderItem;
use App\Services\ServiceOrderItem\ServiceOrderItemService;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use App\Notification\NotifyService as notify;
use Illuminate\Support\Facades\Log;
use Leandrocfe\FilamentPtbrFormFields\Money;

final class EditItemAction
{
    public static function make(): EditAction
    {
        return EditAction::make()
            ->schema([
                Select::make('service_id')
                    ->label('Serviço')
                    ->searchable()
                    ->relationship('service', 'name', function ($query) {
                        $query->where('services.company_id', Filament::getTenant()->id);
                    })
                    ->required()
                    ->columnSpanFull()
                    ->live()
                    ->afterStateUpdated(function (Set $set, callable $get, $state) {
                        $service = \App\Models\Service::find($state);
                        if ($service) {
                            $set('unit_price', number_format($service->price, 2, ',', ''));
                        } else {
                            $set('unit_price', null);
                        }
                        self::calculateValues($get, $set);
                    }),
                Group::make()
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('quantity')
                            ->label('Quantidade')
                            ->required()
                            ->numeric()
                            ->default(1)
                            ->minValue(0)
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn ($state, Set $set, callable $get) => self::calculateValues($get, $set)),
                        Money::make('unit_price')
                            ->label('Preço Unitário')
                            ->required()
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn ($state, Set $set, callable $get) => self::calculateValues($get, $set)),
                        Money::make('subtotal')
                            ->label('Subtotal')
                            ->readOnly(),
                    ]),
                Group::make()
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        Money::make('discount_percentage')
                            ->label('Desconto (%)')
                            ->numeric()
                            ->default(0.0)
                            ->minValue(0)
                            ->maxValue(100)
                            ->suffix('%')
                            ->prefix(null)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function ($state, Set $set, callable $get) {
                                $subtotal = (float) ($get('subtotal') ?? 0);
                                $percentage = (float) ($state ?? 0);
                                $discountAmount = $subtotal * ($percentage / 100);
                                $set('discount_amount', number_format($discountAmount, 2, ',', '.'));
                                self::calculateValues($get, $set);
                            }),
                        Money::make('discount_amount')
                            ->label('Desconto (R$)')
                            ->default(0.0)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function ($state, Set $set, callable $get) {
                                $subtotal = (float) ($get('subtotal') ?? 0);
                                $discountAmount = (float) ($state ?? 0);
                                if ($subtotal > 0) {
                                    $percentage = ($discountAmount / $subtotal) * 100;
                                    $set('discount_percentage', number_format($percentage, 2, ',', '.'));
                                }
                                self::calculateValues($get, $set);
                            }),
                        Money::make('total_amount')
                            ->label('Valor Total')
                            ->readOnly(),
                    ]),
                Textarea::make('observations')
                    ->label('Observações')
                    ->columnSpanFull(),
            ])
            ->using(function (ServiceOrderItem $record, array $data): ?Model {
                Log::debug('Iniciando atualização de item via RelationManager', [
                    'metodo' => __METHOD__ . '@' . __LINE__,
                    'item_id' => $record->id,
                    'data' => $data,
                ]);

                $service = new ServiceOrderItemService();
                $item = $service->update($record, $data, Auth::id());

                if ($service->hasError()) {
                    notify::error(message: $service->getMessageUser(), errorCode: $service->getErrorCode());
                    return null;
                }

                notify::success(message: $service->getMessageUser());
                return $item;
            });
    }

    protected static function calculateValues(callable $get, Set $set): void
    {
        $quantity = (float) ($get('quantity') ?? 0);
        $unitPrice = (float) ($get('unit_price') ?? 0);
        $discountAmount = (float) ($get('discount_amount') ?? 0);

        // Calcula o subtotal
        $subtotal = $quantity * $unitPrice;
        $set('subtotal', number_format($subtotal, 2, ',', '.'));

        // Calcula o total
        $totalAmount = $subtotal - $discountAmount;
        $set('total_amount', number_format($totalAmount, 2, ',', '.'));
    }
}
