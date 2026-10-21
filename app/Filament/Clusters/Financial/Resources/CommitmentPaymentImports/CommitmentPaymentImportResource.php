<?php

namespace App\Filament\Clusters\Financial\Resources\CommitmentPaymentImports;

use App\Enum\Commitment\ImportType;
use App\Filament\Clusters\Financial\Resources\CommitmentImports\CommitmentImportResource;
use App\Filament\Clusters\Financial\Resources\CommitmentImports\RelationManagers\FilesRelationManager;
use App\Filament\Clusters\Financial\Resources\CommitmentPaymentImports\Pages\ListCommitmentPaymentImports;
use App\Filament\Clusters\Financial\Resources\CommitmentPaymentImports\Pages\ViewCommitmentPaymentImport;
use App\Filament\Clusters\Financial\Resources\CommitmentPaymentImports\RelationManagers\PaymentLinesRelationManager;
use App\Models\CommitmentImport;
use App\Models\CommitmentReceipt;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CommitmentPaymentImportResource extends CommitmentImportResource
{
    protected static ?string $slug = 'commitment-payment-imports';

    protected static ?string $navigationLabel = 'Pagamentos de empenhos';

    protected static ?string $modelLabel = 'relatório de pagamentos de empenhos';

    protected static ?string $pluralModelLabel = 'Relatórios de pagamentos de empenhos';

    protected static ?int $navigationSort = 3;

    public static function importType(): ImportType
    {
        return ImportType::PAYMENTS;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withCount(['paymentLines', 'paymentLines as matched_lines_count' => fn ($query) => $query->where('commitment_payment_lines.status', 'matched'),
            'paymentLines as received_lines_count' => fn ($query) => $query->whereIn('commitment_payment_lines.status', ['received', 'already_received'])]);
    }

    public static function table(Table $table): Table
    {
        $table = parent::table($table);

        return $table->columns([...$table->getColumns(),
            TextColumn::make('payment_lines_count')->label('Pagamentos lidos'), TextColumn::make('matched_lines_count')->label('Compatíveis'),
            TextColumn::make('received_lines_count')->label('Recebidos/identificados'),
            TextColumn::make('linked_total')->label('Total vinculado')->money('BRL', divideBy: 100, locale: 'pt_BR')
                ->state(fn (CommitmentImport $record) => CommitmentReceipt::forCustomer($record->company_id, $record->customer_id)->where('status', 'received')
                    ->whereHas('reportLines', fn ($lines) => $lines->whereHas('importFile', fn ($files) => $files->where('commitment_import_id', $record->id)))->sum('amount')),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListCommitmentPaymentImports::route('/'), 'view' => ViewCommitmentPaymentImport::route('/{record}')];
    }

    public static function getRelations(): array
    {
        return [PaymentLinesRelationManager::class, FilesRelationManager::class];
    }
}
