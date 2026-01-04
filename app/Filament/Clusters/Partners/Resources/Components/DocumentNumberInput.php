<?php

namespace App\Filament\Clusters\Partners\Resources\Components;

use App\Models\Partner;
use Filament\Actions\Action;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Icon;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Log;
use Leandrocfe\FilamentPtbrFormFields\Document;
use Livewire\Component;

class DocumentNumberInput
{
    public static function make(): Document
    {
        return Document::make('document_number')
            ->label('Nº do Doc.')
            ->autocomplete(false)
            ->columnSpan(['md' => 2, 'lg' => 3])
            ->dynamic()
            ->live(onBlur: true)
            ->afterStateUpdated(function (Set $set, Field $component, $state) {
                if ($state) {
                    $partner = Partner::where('document_number', $state)->get()->first();
                    if ($partner) {

                        //TODO: Verificar se existe vinculo entre parceiro e empresa
                        $component->afterLabel([Icon::make(Heroicon::CheckCircle), 'Parceiro já cadastrado']);
                        $component->belowContent(self::clearFields());
                        $set('document_type', $partner->document_type);
                        $set('name', $partner->name);
                        $set('state_tax_id', $partner->state_tax_id ?? '');
                        $set('municipal_tax_id', $partner->municipal_tax_id ?? '');
                        $set('state_tax_indicator', $partner->state_tax_indicator ?? '');
                        $set('partner_exists', true);
                        return;
                    }
                }

                $set('name', null);
                $set('state_tax_id', null);
                $set('municipal_tax_id', null);
                $set('state_tax_indicator', null);
                $set('partner_exists', false);

                $component->afterLabel(null);
                $component->belowContent(null);

            });
    }

    private static function clearFields(): Action
    {
        Log::debug(__METHOD__);

        return Action::make('clear-fields')
            ->label('Limpar campos')
            ->action(function (Set $set, Field $component) {
                $set('name', null);
                $set('state_tax_id', null);
                $set('municipal_tax_id', null);
                $set('state_tax_indicator', null);
                $set('partner_exists', false);
                $component->afterLabel(null);
                $component->belowContent(null);
            });
    }
}
