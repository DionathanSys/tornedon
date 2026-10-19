<?php

namespace App\Filament\Clusters\Financial\Resources\CommitmentPaymentImports;

use App\Enum\Commitment\ImportType;
use App\Filament\Clusters\Financial\Resources\CommitmentImports\CommitmentImportResource;
use App\Filament\Clusters\Financial\Resources\CommitmentPaymentImports\Pages\ListCommitmentPaymentImports;
use App\Filament\Clusters\Financial\Resources\CommitmentPaymentImports\Pages\ViewCommitmentPaymentImport;

class CommitmentPaymentImportResource extends CommitmentImportResource
{
    protected static ?string $slug = 'commitment-payment-imports';

    protected static ?string $navigationLabel = 'Pagamentos de empenhos';

    protected static ?string $modelLabel = 'relatório de pagamentos de empenhos';

    protected static ?string $pluralModelLabel = 'Relatórios de pagamentos de empenhos';

    protected static ?int $navigationSort = 8;

    public static function importType(): ImportType
    {
        return ImportType::PAYMENTS;
    }

    public static function getPages(): array
    {
        return ['index' => ListCommitmentPaymentImports::route('/'), 'view' => ViewCommitmentPaymentImport::route('/{record}')];
    }
}
