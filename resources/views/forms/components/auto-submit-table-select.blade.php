@php
    use App\Forms\Components\Livewire\AutoSubmitTableSelectLivewireComponent;

    $fieldWrapperView = $getFieldWrapperView();
    $extraAttributes = $getExtraAttributes();
    $id = $getId();
@endphp

<x-dynamic-component :component="$fieldWrapperView" :field="$field">
    <div
        {{
            $attributes
                ->merge([
                    'id' => $id,
                ], escape: false)
                ->merge($extraAttributes, escape: false)
        }}
    >
        @livewire(AutoSubmitTableSelectLivewireComponent::class, [
            'isDisabled' => $isDisabled(),
            'isMultiple' => $isMultiple(),
            'maxSelectableRecords' => $getMaxItems(),
            'model' => $getModel(),
            'record' => $getRecord(),
            'relationshipName' => $getRelationshipName(),
            'shouldIgnoreRelatedRecords' => $shouldIgnoreRelatedRecords(),
            'tableConfiguration' => base64_encode($getTableConfiguration()),
            'tableArguments' => $getTableArguments(),
            $applyStateBindingModifiers('wire:model') => $getStatePath(),
        ], key($getLivewireKey()))
    </div>
</x-dynamic-component>
