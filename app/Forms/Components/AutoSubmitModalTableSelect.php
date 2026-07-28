<?php

namespace App\Forms\Components;

use Filament\Forms\Components\ModalTableSelect;
use Filament\Forms\Components\TableSelect;

class AutoSubmitModalTableSelect extends ModalTableSelect
{
    public function getTableSelect(): TableSelect
    {
        $select = AutoSubmitTableSelect::make('selection')
            ->label($this->getLabel())
            ->hiddenLabel()
            ->tableConfiguration($this->getTableConfiguration())
            ->multiple($this->isMultiple())
            ->maxItems($this->getMaxItems())
            ->tableArguments($this->getTableArguments());

        if (filled($this->getRelationshipName())) {
            $select->relationshipName($this->getRelationshipName());
        }

        if ($this->modifyTableSelectUsing) {
            $select = $this->evaluate(
                $this->modifyTableSelectUsing,
                namedInjections: [
                    'select' => $select,
                    'tableSelect' => $select,
                ],
                typedInjections: [
                    TableSelect::class => $select,
                ],
            ) ?? $select;
        }

        return $select;
    }
}
