<?php

namespace App\Filament\Clusters\Sales\Resources\Quotes\Schemas\Components;

use App\Enum\Quote\Destination;
use App\Enum\Quote\Status;
use App\Filament\Clusters\Sales\Resources\Quotes\Schemas\Components\ModalSelectProductForProduction;
use App\Filament\Clusters\Sales\Resources\Quotes\Schemas\Components\ModalSelectProductStock;
use App\Filament\Clusters\Sales\Resources\Quotes\Schemas\Components\ModalSelectService;
use App\Filament\Tables\ProductsStockTable;
use App\Filament\Tables\ProductTable;
use App\Filament\Tables\ServiceTable;
use App\Services\QuoteItem\QuoteItemResolverService;
use App\Filament\Clusters\Sales\Resources\Components\ItemValueGroup;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\ModalTableSelect;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

class SchemaForm
{

    /**
     * O ponto de entrada do formulário.
     */
    public static function make(string $context = 'create'): array
    {
        return [
            self::getSelectionGroup(),
            self::getInformationGroup(),
            ItemValueGroup::make(),
            Textarea::make('description')
                ->label('Descrição')
                ->columnSpanFull(),
        ];
    }

    /**
     * Grupo de Seleção: Contém os campos de busca via ModalTableSelect.
     */
    private static function getSelectionGroup(): Group
    {
        return Group::make()
            ->columns(3)
            ->columnSpanFull()
            ->schema([
                ModalSelectProductStock::make(),
                ModalSelectProductForProduction::make(),
                ModalSelectService::make(),
            ]);
    }

    /**
     * Grupo de Informação: Exibe os dados de identificação do item selecionado.
     */
    private static function getInformationGroup(): Group
    {
        return Group::make()
            ->columns(3)
            ->columnSpanFull()
            ->schema([
                // Campos ocultos para metadados de seleção (dentro de item para não serem salvos direto)
                Hidden::make('item.real_product_id'),
                Hidden::make('item.real_service_id'),
                Hidden::make('item.min_sale_price')
                    ->saved(false)
                    ->default(0),
                Hidden::make('item.code')
                    ->saved(false),
                Hidden::make('item.name')
                    ->saved(false),

                TextInput::make('item.identification')
                    ->label('Identificação do Item')
                    ->saved(false)
                    ->readOnly()
                    ->dehydrated(false)
                    ->columnSpanFull(),

                TextInput::make('unit_of_measure')
                    ->label('Unidade de Medida')
                    ->disabled()
                    ->saved(true)
                    ->columnSpan(1),

                Select::make('destination')
                    ->label('Finalidade')
                    ->options(Destination::toSelectArray())
                    ->disabled()
                    ->saved(true)
                    ->columnSpan(2),
            ]);
    }

    /**
     * Resolve os dados do item através do serviço especialista.
     */
    public static function resolveItem(Set $set, Get $get, Destination $type, $id): void
    {
        if (! $id) return;

        // Limpa as outras seleções para manter integridade dentro do container 'item'
        $set('item.product_stock_id',    $type === Destination::REQUISITION      ? $id : null);
        $set('item.product_id',          $type === Destination::ORDER_PRODUCTION ? $id : null);
        $set('item.service_id',          $type === Destination::ORDER_SERVICE    ? $id : null);

        $service = app(QuoteItemResolverService::class);

        $dto = match ($type) {
            Destination::REQUISITION        => $service->resolveForStock($id),
            Destination::ORDER_PRODUCTION   => $service->resolveForProduct($id),
            Destination::ORDER_SERVICE      => $service->resolveForService($id),
        };

        if ($dto) {
            // Guardamos os IDs reais e metadados dentro de 'item.'
            $set('item.real_product_id', $dto->productId);
            $set('item.real_service_id', $dto->serviceId);
            $set('item.code', $dto->code);
            $set('item.name', $dto->name);
            $set('item.identification', $dto->code ? "[{$dto->code}] {$dto->name}" : $dto->name);

            // Campos de persistência (Root)
            $set('unit_of_measure', $dto->unit);
            $set('destination', $dto->destination->value);
            $set('unit_price', number_format($dto->price, 2, ',', '.'));
            $set('item.min_sale_price', $dto->minSalePrice);

            // Reseta descontos ao trocar de item
            $set('discount_amount', '0,00');
            $set('discount_percentage', '0,00');

            ItemValueGroup::recalculate($get, $set);
        }
    }
}
