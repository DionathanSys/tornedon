<x-operation.page title="Início" subtitle="Acompanhe os atendimentos e sua fila de trabalho.">
    <section class="space-y-3">
        <h2 class="text-lg font-semibold">Hoje</h2>
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <x-mary-stat title="OS no dia" :value="$todayStats['total'] ?? 0" icon="o-clipboard-document-list" />
            <x-mary-stat title="Abertas" :value="$todayStats['open'] ?? 0" icon="o-clock" color="text-info" />
            <x-mary-stat title="Encerradas" :value="$todayStats['closed'] ?? 0" icon="o-check-circle" color="text-success" />
            <x-mary-stat title="Faturamento" :value="$todayStats['revenue'] ?? 'R$ 0,00'" />
        </div>
    </section>
    <section class="space-y-3">
        <h2 class="text-lg font-semibold">Minhas Ordens</h2>
        <div class="grid grid-cols-2 gap-3">
            <x-mary-stat title="Pendentes" :value="$myStats['pending'] ?? 0" icon="o-inbox" />
            <x-mary-stat title="Agendadas hoje" :value="$myStats['scheduled_today'] ?? 0" icon="o-calendar-days" color="text-warning" />
        </div>
    </section>
    <x-mary-card title="Acesso rápido" shadow>
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <x-mary-button label="Ordens de Serviço" icon="o-clipboard-document-list" :link="\App\Filament\Operation\Pages\ServiceOrders\ServiceOrderQueue::getUrl()" class="btn-outline justify-start" />
            <x-mary-button label="Requisições" icon="o-clipboard-document" :link="\App\Filament\Operation\Pages\Requisitions\RequisitionList::getUrl()" class="btn-outline justify-start" />
        </div>
    </x-mary-card>
    @if (count($recentOrders))
        <section class="space-y-3">
            <h2 class="text-lg font-semibold">Últimas OS abertas</h2>
            @foreach ($recentOrders as $order)
                <a href="{{ $order['url'] }}" wire:navigate class="block" wire:key="recent-order-{{ $order['number'] }}">
                    <x-mary-card shadow>
                        <div class="flex items-center justify-between gap-3">
                            <span class="font-bold">OS #{{ $order['number'] }}</span>
                            <x-operation.status :value="$order['status']" :color="$order['status_color']" />
                        </div>
                        <p class="mt-2 text-sm">{{ $order['customer'] }}</p>
                        <p class="mt-1 text-xs text-base-content/60">{{ $order['equipment'] }} · {{ $order['order_date'] }}</p>
                    </x-mary-card>
                </a>
            @endforeach
        </section>
    @endif
</x-operation.page>
