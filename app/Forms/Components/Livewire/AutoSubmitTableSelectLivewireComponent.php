<?php

namespace App\Forms\Components\Livewire;

use Filament\Forms\Components\TableSelect\Livewire\TableSelectLivewireComponent;
use Filament\Tables\Table;

class AutoSubmitTableSelectLivewireComponent extends TableSelectLivewireComponent
{
    public bool $isMultiple = false;

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->recordAction($this->isMultiple ? null : 'selectTableRecord');
    }

    public function selectTableRecord(string $recordKey): void
    {
        $this->state = $recordKey;

        if (! $this->isMultiple) {
            $this->js(<<<'JS'
                setTimeout(() => {
                    const form = $el.closest('form')
                    const submitButton = form?.querySelector('button[type="submit"]')

                    if (submitButton) {
                        submitButton.click()

                        return
                    }

                    form?.requestSubmit()
                }, 0)
            JS);
        }
    }
}
