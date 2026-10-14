@include('pwa.register-sw')

@php
    $pageClass = str(request()->route()?->getActionName() ?? '')->before('@')->toString();
    $isRecord = in_array($pageClass, [
        \App\Filament\Operation\Pages\ServiceOrders\ServiceOrderDetail::class,
        \App\Filament\Operation\Pages\Requisitions\RequisitionDetail::class,
    ], true);
    $isRecord = $isRecord || (is_a($pageClass, \App\Filament\Operation\ModuleCreatePage::class, true) || is_a($pageClass, \App\Filament\Operation\ModuleEditPage::class, true) || $pageClass === \App\Filament\Operation\Resources\ProductionRequests\Pages\EditProductionRequest::class);
    $tenant = filament()->getTenant();
    $navigation = \App\Filament\Operation\OperationModules::navigation();
@endphp

@if ($tenant)
    <x-operation.theme>
        @if (! $isRecord)
            <x-operation.bottom-bar :columns="min(count($navigation), 4) + 1">
                @foreach (array_slice($navigation, 0, 4) as $item)
                    <x-mary-button :label="$item['label']" :icon="$item['icon']" :link="$item['url']" class="btn-ghost operation-bar-button" />
                @endforeach
                @livewire(\App\Livewire\OperationMenu::class)
            </x-operation.bottom-bar>
        @endif
        <x-mary-toast />
    </x-operation.theme>
    @livewire(\App\Livewire\OperationRecordCreator::class)
    @livewire(\App\Livewire\OperationModuleCreator::class)
@endif

<script data-navigate-once>
    if (!window.operationKeyboardScroll && window.visualViewport) {
        window.operationKeyboardScroll = true;
        let activeField = null;
        let timer = null;
        const scroll = () => {
            clearTimeout(timer);
            timer = setTimeout(() => {
                if (activeField && document.contains(activeField) && window.visualViewport.height < window.innerHeight - 80) {
                    activeField.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                }
            }, 180);
        };
        document.addEventListener('focusin', event => {
            if (event.target.matches('input:not([type="hidden"]), textarea, select, [contenteditable="true"]')) {
                activeField = event.target;
                scroll();
            }
        });
        document.addEventListener('focusout', () => { activeField = null; });
        window.visualViewport.addEventListener('resize', scroll);
    }
</script>
