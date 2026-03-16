<?php

namespace App\Filament\Clusters\Sales\Resources\FiscalDocuments\RelationManagers\Actions;

use App\Enum\Product\Origin;
use App\Enum\Product\Unit;
use App\Filament\Clusters\Sales\Resources\Components\ItemValueGroup;
use App\Filament\Clusters\Sales\Resources\Quotes\Schemas\Components\ModalSelectProductStock;
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
use Filament\Schemas\Components\Section;
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Leandrocfe\FilamentPtbrFormFields\Money;

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
                ModalSelectProductStock::make('product_id')
                    ->label('Estoque do Produto')
                    ->required()
                    ->columnSpanFull(),

                TextInput::make('description')
                    ->label('Descrição')
                    ->maxLength(255)
                    ->columnSpanFull(),

                Group::make()
                    ->columns(3)
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
                    ]),

                ItemValueGroup::make(),

                Section::make()
                    ->columns(3)
                    ->collapsible()
                    ->collapsed()
                    ->columnSpanFull()
                    ->schema([
                        Money::make('freight_amount')
                            ->label('Frete'),
                        Money::make('insurance_amount')
                            ->label('Seguro'),
                        Money::make('other_expenses_amount')
                            ->label('Outras'),
                    ]),

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
