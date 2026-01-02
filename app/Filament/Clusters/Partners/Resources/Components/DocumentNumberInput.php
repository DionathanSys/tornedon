<?php

namespace App\Filament\Clusters\Partners\Resources\Components;

use App\Models\Partner;
use Filament\Actions\Action;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Icon;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Leandrocfe\FilamentPtbrFormFields\Document;

class DocumentNumberInput
{
    public static function make(): Document
    {
        return Document::make('document_number')
            ->label('Nº do Doc.')
            ->afterLabel('teste')
            ->belowContent([
                Icon::make(Heroicon::InformationCircle),
                'This is the user\'s full name.',
                Action::make('generate'),
            ])
            ->autocomplete(false)
            ->disabledOn('edit')
            ->columnSpan(['md' => 2, 'lg' => 3])
            ->dynamic()
            ->live(onBlur: true)
            ->afterStateUpdated(function (Set $set, Field $component, $state) {
                // ds($state);
                if($state){
                    $partner = Partner::where('document_number', $state);
                    // ds($partner);
                    if($partner){
                        $component->afterLabel([Icon::make(Heroicon::CheckCircle),'Parceiro com cadastrado']);
                    } else {
                        $component->afterLabel([Icon::make(Heroicon::CheckCircle),'Parceiro sem cadastrado']);
                    }
                }
            });
    }
}
