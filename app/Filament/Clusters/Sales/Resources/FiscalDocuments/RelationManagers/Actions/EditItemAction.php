<?php

namespace App\Filament\Clusters\Sales\Resources\FiscalDocuments\RelationManagers\Actions;

use App\Enum\Product\Origin;
use App\Enum\Product\Unit;
use App\Filament\Clusters\Sales\Resources\Components\ItemValueGroup;
use App\Models\FiscalDocumentItem;
use App\Notification\NotifyService as notify;
use App\Services\FiscalDocumentItem\FiscalDocumentItemService;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Group;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

final class EditItemAction
{
    public static function make(): EditAction
    {
        return EditAction::make()
            ->label('Editar')
            ->visible(fn (RelationManager $livewire): bool => ! $livewire->getOwnerRecord()->nfeSent())
            ->schema([
                Select::make('product_id')
                    ->label('Produto')
                    ->relationship('product', 'name')
                    ->searchable()
                    ->required()
                    ->native(false)
                    ->columnSpanFull(),

                TextInput::make('description')
                    ->label('Descrição')
                    ->maxLength(255)
                    ->columnSpanFull(),

                Group::make()
                    ->columns(4)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('ncm_code')
                            ->label('NCM')
                            ->maxLength(8),
                        TextInput::make('cest_code')
                            ->label('CEST')
                            ->maxLength(9),
                        TextInput::make('cfop_code')
                            ->label('CFOP')
                            ->maxLength(4),
                        TextInput::make('barcode')
                            ->label('Código de Barras')
                            ->maxLength(60),
                    ]),

                Group::make()
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        Select::make('product_origin')
                            ->label('Origem')
                            ->options(Origin::toSelectArray())
                            ->native(false),
                        Select::make('unit_of_measure')
                            ->label('Unidade Comercial')
                            ->options(Unit::toSelectArray())
                            ->required()
                            ->native(false),
                        TextInput::make('quantity')
                            ->label('Quantidade')
                            ->numeric()
                            ->required(),
                    ]),

                ItemValueGroup::make(),

                Group::make()
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('freight_amount')
                            ->label('Frete')
                            ->numeric()
                            ->default(0)
                            ->prefix('R$'),
                        TextInput::make('insurance_amount')
                            ->label('Seguro')
                            ->numeric()
                            ->default(0)
                            ->prefix('R$'),
                        TextInput::make('other_expenses_amount')
                            ->label('Outras Despesas')
                            ->numeric()
                            ->default(0)
                            ->prefix('R$'),
                    ]),

                Group::make()
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        Select::make('taxable_unit')
                            ->label('Unidade Tributável')
                            ->options(Unit::toSelectArray())
                            ->native(false)
                            ->helperText('Se diferente da unidade comercial'),
                        TextInput::make('taxable_quantity')
                            ->label('Qtd. Tributável')
                            ->numeric(),
                        TextInput::make('taxable_unit_price')
                            ->label('Valor Unit. Tributável')
                            ->numeric()
                            ->prefix('R$'),
                    ]),

                Toggle::make('included_in_total')
                    ->label('Inclui no Total')
                    ->default(true),

                Textarea::make('additional_information')
                    ->label('Informações Adicionais do Item')
                    ->rows(2)
                    ->maxLength(500)
                    ->columnSpanFull(),
            ])
            ->using(function (FiscalDocumentItem $record, array $data): ?Model {
                Log::debug('Atualizando item de nota fiscal via RelationManager', [
                    'metodo'  => __METHOD__ . '@' . __LINE__,
                    'item_id' => $record->id,
                    'data'    => $data,
                ]);

                $service = new FiscalDocumentItemService();
                $item = $service->update($record, $data, Auth::id());

                if ($service->hasError()) {
                    notify::error(message: $service->getMessage(), errorCode: $service->getErrorCode());
                    return null;
                }

                notify::success(message: $service->getMessage());
                return $item;
            });
    }
}
