<?php

namespace App\Filament\Clusters\Inventory\Resources\StockMovements\Pages\Actions;

use App\Models\Product;
use App\Notification\NotifyService as notify;
use App\Services\StockMovement\Actions\PrintKardexPdfAction;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Support\Icons\Heroicon;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DownloadKardexPdfAction
{
    public static function make(): Action
    {
        return Action::make('downloadKardexPdf')
            ->label('Relatório Kardex')
            ->icon(Heroicon::DocumentArrowDown)
            ->color('gray')
            ->modalHeading('Gerar relatório Kardex')
            ->schema([
                Select::make('product_id')
                    ->label('Produto')
                    ->required()
                    ->searchable()
                    ->preload()
                    ->getSearchResultsUsing(fn (string $search): array => Product::query()
                        ->where('company_id', Filament::getTenant()->id)
                        ->where(function ($query) use ($search): void {
                            $query->where('product_code', 'like', "%{$search}%")
                                ->orWhere('name', 'like', "%{$search}%");
                        })
                        ->orderBy('product_code')
                        ->limit(50)
                        ->get()
                        ->mapWithKeys(fn (Product $product): array => [
                            $product->id => trim("[{$product->product_code}] {$product->name}"),
                        ])
                        ->all())
                    ->getOptionLabelUsing(fn ($value): ?string => Product::query()
                        ->where('company_id', Filament::getTenant()->id)
                        ->whereKey($value)
                        ->get()
                        ->map(fn (Product $product): string => trim("[{$product->product_code}] {$product->name}"))
                        ->first())
                    ->native(false),
                DatePicker::make('start_date')
                    ->label('Data Inicial'),
                DatePicker::make('end_date')
                    ->label('Data Final')
                    ->afterOrEqual('start_date'),
            ])
            ->action(function (array $data): StreamedResponse {
                $companyId = Filament::getTenant()->id;

                $product = Product::query()
                    ->where('company_id', $companyId)
                    ->whereKey($data['product_id'])
                    ->first();

                if (! $product) {
                    notify::error('Produto nao encontrado para gerar o kardex.');

                    return response()->streamDownload(fn () => null, 'kardex.pdf');
                }

                $pdf = app(PrintKardexPdfAction::class)->execute($product, $companyId, [
                    'start_date' => $data['start_date'] ?? null,
                    'end_date' => $data['end_date'] ?? null,
                ]);

                if (! $pdf) {
                    notify::error('Nao foi possivel gerar o PDF do kardex.');

                    return response()->streamDownload(fn () => null, 'kardex.pdf');
                }

                $filename = sprintf('kardex-%s.pdf', $product->product_code ?: $product->id);

                return response()->streamDownload(function () use ($pdf): void {
                    echo base64_decode($pdf);
                }, $filename, ['Content-Type' => 'application/pdf']);
            });
    }
}
