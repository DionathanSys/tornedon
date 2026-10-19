<?php

namespace App\Filament\Clusters\Financial\Resources\CustomerCommitments\Pages;

use App\Enum\Commitment\MatchStatus;
use App\Filament\Clusters\Financial\Resources\CommitmentImports\Actions\ConfigureCommitmentsAction;
use App\Filament\Clusters\Financial\Resources\CommitmentImports\Actions\ImportCommitmentFilesAction;
use App\Filament\Clusters\Financial\Resources\CommitmentImports\CommitmentImportResource;
use App\Filament\Clusters\Financial\Resources\CustomerCommitments\CustomerCommitmentResource;
use App\Models\CustomerCommitment;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;

class ListCustomerCommitments extends ListRecords
{
    protected static string $resource = CustomerCommitmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ImportCommitmentFilesAction::make(CommitmentImportResource::class),
            ConfigureCommitmentsAction::make(),
            Action::make('imports')->label('Histórico de importações')->url(CommitmentImportResource::getUrl('index')),
        ];
    }

    public function getTabs(): array
    {
        $tabs = ['all' => Tab::make('Todos')];
        foreach (MatchStatus::cases() as $status) {
            $tabs[$status->value] = Tab::make($status->description())
                ->badge(fn () => CustomerCommitment::forCompany(Filament::getTenant()->id)
                    ->when(data_get($this->tableFilters, 'customer_id.value'), fn ($query, $customerId) => $query->where('customer_id', $customerId))
                    ->where('status', $status->value)->count())
                ->modifyQueryUsing(fn ($query) => $query->where('status', $status->value));
        }

        return $tabs;
    }
}
