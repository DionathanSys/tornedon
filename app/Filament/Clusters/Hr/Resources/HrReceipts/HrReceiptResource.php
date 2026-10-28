<?php

namespace App\Filament\Clusters\Hr\Resources\HrReceipts;

use App\Filament\Clusters\Hr\HrCluster;
use App\Filament\Clusters\Hr\Resources\HrReceipts\Pages\ManageHrReceipts;
use App\Filament\Concerns\AuthorizesHrResource;
use App\Models\HrReceipt;
use App\Services\Hr\HrService;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\URL;

class HrReceiptResource extends Resource
{
    use AuthorizesHrResource;

    protected static ?string $model = HrReceipt::class;

    protected static ?string $cluster = HrCluster::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-check';

    protected static ?string $modelLabel = 'Recibo de RH';

    protected static ?string $pluralModelLabel = 'Recibos de RH';

    protected static string $hrModule = 'hr_receipts';

    protected static ?int $navigationSort = 3;

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->label('Recibo')->sortable(),
            TextColumn::make('snapshot.person_name')->label('Beneficiário'),
            TextColumn::make('snapshot.competence')->label('Competência'),
            TextColumn::make('snapshot.nature')->label('Natureza'),
            TextColumn::make('snapshot.payment_amount')->label('Valor pago')->money('BRL'),
            TextColumn::make('snapshot.payment_date')->label('Data do pagamento'),
            TextColumn::make('validity')->label('Situação')->badge()->state(fn (HrReceipt $record) => app(HrService::class)->receiptIsValid($record) ? 'Válido' : 'Invalidado'),
            TextColumn::make('created_at')->label('Emissão')->dateTime('d/m/Y H:i'),
        ])->recordActions([
            Action::make('download')->label('Abrir PDF')->icon('heroicon-o-arrow-down-tray')
                ->visible(fn (HrReceipt $record) => static::allowed('export', $record) && app(HrService::class)->receiptIsValid($record))
                ->url(fn (HrReceipt $record) => URL::temporarySignedRoute('hr.receipt', now()->addMinutes(10), ['company' => $record->company_id, 'receipt' => $record->id]))->openUrlInNewTab(),
        ])->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ManageHrReceipts::route('/')];
    }
}
