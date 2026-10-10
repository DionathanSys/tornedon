@include('pwa.register-sw')

@php
    $pageClass = str(request()->route()?->getActionName() ?? '')->before('@')->toString();
    $isRecord = in_array($pageClass, [
        \App\Filament\Operation\Pages\ServiceOrders\ServiceOrderDetail::class,
        \App\Filament\Operation\Pages\Requisitions\RequisitionDetail::class,
    ], true);
    $tenant = filament()->getTenant();
@endphp

@if ($tenant)
    <x-operation.theme>
        @if (! $isRecord)
            <x-operation.bottom-bar>
                <x-mary-button label="Início" icon="o-home" :link="\App\Filament\Operation\Pages\OperationDashboard::getUrl(tenant: $tenant)" class="btn-ghost operation-bar-button" />
                <x-mary-button label="Ordens" icon="o-clipboard-document-list" :link="\App\Filament\Operation\Pages\ServiceOrders\ServiceOrderQueue::getUrl(tenant: $tenant)" class="btn-ghost operation-bar-button" />
                <x-mary-button label="Requisições" icon="o-clipboard-document" :link="\App\Filament\Operation\Pages\Requisitions\RequisitionList::getUrl(tenant: $tenant)" class="btn-ghost operation-bar-button" />
                @livewire(\App\Livewire\OperationMenu::class)
            </x-operation.bottom-bar>
        @endif
        <x-mary-toast />
    </x-operation.theme>
    @livewire(\App\Livewire\OperationRecordCreator::class)
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
