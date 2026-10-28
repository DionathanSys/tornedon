<?php

namespace App\Filament\Bpo\Resources\AccountReceivables\RelationManagers;

use App\Filament\Bpo\Resources\AccountReceivables\Schemas\PaymentEditForm;
use App\Filament\Bpo\Resources\Concerns\HasCompactFinancialRelationship;
use Filament\Actions\ActionGroup;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class PaymentsRelationManager extends \App\Filament\Clusters\Financial\Resources\AccountReceivables\RelationManagers\PaymentsRelationManager
{
    use HasCompactFinancialRelationship;

    protected static bool $isLazy = false;

    public function table(Table $table): Table
    {
        $table = $this->compactFinancialTable(parent::table($table), payments: true);
        foreach ($table->getRecordActions() as $group) {
            if (! $group instanceof ActionGroup) {
                continue;
            }
            $group->icon(Heroicon::Bars3)->label('Ações do recebimento')->tooltip('Ações do recebimento')->iconButton();
            foreach ($group->getFlatActions() as $action) {
                $action->grouped()->hiddenLabel(false);
                if ($action->getName() === 'edit_payment') {
                    $action->modalHeading('Editar recebimento')->modalWidth(Width::FourExtraLarge)->slideOver(false)
                        ->modalSubmitActionLabel('Salvar alterações')->schema(PaymentEditForm::components());
                }
            }
        }

        return $table;
    }
}
