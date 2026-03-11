<?php

namespace App\Filament\Clusters\Sales\Resources\FiscalDocuments\RelationManagers\Actions;

use App\Enum\Product\Origin;
use App\Enum\Product\Unit;
use App\Models\FiscalDocumentItem;
use App\Notification\NotifyService as notify;
use App\Services\FiscalDocumentItem\FiscalDocumentItemService;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Group;
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

final class CreateItemAction
{
    public static function make(): CreateAction
    {
        return CreateAction::make()
            ->label('Adicionar Item')
            ->icon(Heroicon::Plus)
            ->size(Size::Small)
            ->visible(fn (RelationManager $livewire): bool => ! $livewire->getOwnerRecord()->nfeSent())
            ->modalHeading('Adicionar Item à Nota Fiscal')
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
                            ->default('0')
                            ->native(false),
                        Select::make('unit_of_measure')
                            ->label('Unidade Comercial')
                            ->options(Unit::toSelectArray())
                            ->default('UN')
                            ->required()
                            ->native(false),
                        TextInput::make('quantity')
                            ->label('Quantidade')
                            ->numeric()
                            ->required()
                            ->default(1),
                    ]),

                Group::make()
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('unit_price')
                            ->label('Valor Unitário')
                            ->numeric()
                            ->required()
                            ->prefix('R$'),
                        TextInput::make('discount_amount')
                            ->label('Desconto')
                            ->numeric()
                            ->default(0)
                            ->prefix('R$'),
                        TextInput::make('total_price')
                            ->label('Valor Total')
                            ->numeric()
                            ->required()
                            ->prefix('R$'),
                    ]),

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
            ->using(function (array $data, RelationManager $livewire): ?Model {
                $fiscalDocument = $livewire->getOwnerRecord();

                $data['fiscal_document_id'] = $fiscalDocument->id;

                Log::debug('Criando item de nota fiscal via RelationManager', [
                    'metodo'             => __METHOD__ . '@' . __LINE__,
                    'fiscal_document_id' => $fiscalDocument->id,
                    'data'               => $data,
                ]);

                $service = new FiscalDocumentItemService();
                $item = $service->create($data, Auth::id());

                if ($service->hasError()) {
                    notify::error(message: $service->getMessage(), errorCode: $service->getErrorCode());
                    return null;
                }

                notify::success(message: $service->getMessage());
                return $item;
            })
            ->successNotification(null);
    }
}
