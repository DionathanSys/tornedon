<?php

namespace App\Filament\Clusters\Financial\Resources\SefazDistributionDocuments\Actions;

use App\Models\Product;
use App\Models\SefazDistributionDocument;
use App\Services\Fiscal\Sefaz\SefazDistributionDocumentService;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;

class LinkItemsAction
{
    public static function make(): Action
    {
        return Action::make('linkItems')
            ->label('Vincular itens a produtos')
            ->icon('heroicon-o-link')
            ->modalWidth('5xl')
            ->visible(fn(SefazDistributionDocument $record): bool => $record->full_xml_available && ! empty($record->items_json))
            ->schema(fn(SefazDistributionDocument $record): array => [
                Repeater::make('items')
                    ->label('Itens')
                    ->columnSpanFull()
                    ->columns(12)
                    ->deletable(false)
                    ->addable(false)
                    ->default(
                        collect($record->items_json ?? [])->map(function (array $item): array {
                            return [
                                'line' => $item['line'] ?? null,
                                'product_code' => $item['product_code'] ?? null,
                                'description' => $item['description'] ?? null,
                                'quantity' => $item['quantity'] ?? null,
                                'product_id' => $item['product_id'] ?? null,
                                'product_name' => $item['product_name'] ?? null,
                            ];
                        })->all()
                    )
                    ->schema([
                        TextEntry::make('product_code')
                            ->label('Cód. XML')
                            ->columnSpan(2),
                        TextEntry::make('description')
                            ->label('Descrição XML')
                            ->tooltip(fn($state) => $state)
                            ->words(20)
                            ->columnSpan(6),
                        Select::make('product_id')
                            ->label('Produto interno')
                            ->searchable()
                            ->native(false)
                            ->columnSpan(4)
                            ->options(
                                Product::query()
                                    ->where('company_id', $record->company_id)
                                    ->orderBy('name')
                                    ->get()
                                    ->mapWithKeys(fn(Product $product): array => [
                                        $product->id => trim(($product->product_code ? "[{$product->product_code}] " : '') . $product->name),
                                    ])
                                    ->all()
                            ),
                    ]),
            ])
            ->action(function (SefazDistributionDocument $record, array $data): void {
                $currentItems = collect($record->items_json ?? []);
                $mappedItems = collect($data['items'] ?? []);

                $updatedItems = $currentItems->map(function (array $item, int $index) use ($mappedItems): array {
                    $mapping = $mappedItems->get($index, []);
                    $productId = $mapping['product_id'] ?? null;
                    $product = $productId ? Product::query()->find($productId) : null;
                    $item['product_id'] = $product?->id;
                    $item['product_name'] = $product?->name;

                    return $item;
                })->all();

                app(SefazDistributionDocumentService::class)->updateItemMappings($record, $updatedItems, Auth::id());
                $notification = Notification::make()
                    ->title('Itens vinculados');

                if ($record->partner_id === null) {
                    $notification
                        ->warning()
                        ->body('Os itens foram vinculados neste DF-e, mas o pré-vínculo automático só será salvo após vincular o fornecedor.');
                } else {
                    $notification
                        ->success()
                        ->body('Os vínculos foram salvos e serão reaproveitados nas próximas notas deste fornecedor.');
                }

                $notification->send();
            });
    }
}
