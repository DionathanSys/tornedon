<x-operation.page title="Início" subtitle="Acompanhe os atendimentos e sua fila de trabalho.">
    <section class="space-y-4">
        <h2 class="text-lg font-semibold">Hoje</h2>
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <x-operation.stat title="OS no dia" :value="$todayStats['total'] ?? 0" icon="o-clipboard-document-list" />
            <x-operation.stat title="Abertas" :value="$todayStats['open'] ?? 0" icon="o-clock" color="text-info" />
            <x-operation.stat title="Encerradas" :value="$todayStats['closed'] ?? 0" icon="o-check-circle" color="text-success" />
            <x-operation.stat title="Faturamento" :value="$todayStats['revenue'] ?? 'R$ 0,00'" />
        </div>
    </section>
    <section class="space-y-4">
        <h2 class="text-lg font-semibold">Minhas Ordens</h2>
        <div class="grid grid-cols-2 gap-3">
            <x-operation.stat title="Pendentes" :value="$myStats['pending'] ?? 0" icon="o-inbox" />
            <x-operation.stat title="Agendadas hoje" :value="$myStats['scheduled_today'] ?? 0" icon="o-calendar-days" color="text-warning" />
        </div>
    </section>
    <x-operation.panel title="Acesso rápido">
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <x-mary-button label="Ordens de Serviço" icon="o-clipboard-document-list" :link="\App\Filament\Operation\Pages\ServiceOrders\ServiceOrderQueue::getUrl()" class="btn-outline justify-start" />
            <x-mary-button label="Requisições" icon="o-clipboard-document" :link="\App\Filament\Operation\Pages\Requisitions\RequisitionList::getUrl()" class="btn-outline justify-start" />
        </div>
    </x-operation.panel>
    @if (count($recentOrders))
        <section class="space-y-4">
            <h2 class="text-lg font-semibold">Últimas OS abertas</h2>
            <div class="overflow-hidden rounded-box border border-base-300 bg-base-100 [&_hr]:border-base-300">
                @foreach ($recentOrders as $order)
                    <x-operation.record-item :record="$order" :title="'OS #'.$order['number']" :subtitle="$order['customer']" :status="$order['status']" :color="$order['status_color']" :details="[$order['equipment'].' · '.$order['order_date']]" />
                @endforeach
            </div>
        </section>
    @endif
</x-operation.page>
