<?php

namespace App\Filament\Bpo\Resources\Pages;

use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

abstract class CreateFinancialTitle extends CreateRecord
{
    use \App\Filament\Bpo\Concerns\HasWorkbenchHeader;

    protected static string $layout = 'components.bpo.layout';

    protected string $view = 'filament.bpo.pages.workbench-form';

    public function workbenchEyebrow(): string
    {
        return static::getResource()::$module === 'account_payables' ? 'DESPESA · NOVO LANÇAMENTO' : 'RECEITA · NOVO LANÇAMENTO';
    }

    public function workbenchDescription(): string
    {
        return 'Informe os dados do título e organize seus vencimentos e sua classificação financeira.';
    }

    protected ?bool $hasDatabaseTransactions = true;

    protected function handleRecordCreation(array $data): Model
    {
        $data['company_id'] = Filament::getTenant()->id;
        $service = static::getResource()::service();
        $record = $service->create($data, auth()->id());
        if (! $record || $service->hasError()) {
            throw ValidationException::withMessages($service->getErrors() ?: ['data' => $service->getMessageUser()]);
        }

        return $record;
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->record]);
    }
}
