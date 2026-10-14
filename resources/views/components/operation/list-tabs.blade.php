@props(['active', 'open' => 0, 'closed' => 0, 'all' => 0, 'tabs' => null])
<div class="grid grid-cols-3 gap-2">
    @foreach ($tabs ?? ['open' => ['Abertas', $open], 'closed' => ['Encerradas', $closed], 'all' => ['Todas', $all]] as $key => [$label, $count])
        <x-mary-button :label="$label.' ('.$count.')'" :wire:click="'setTab(\''.$key.'\')'" :class="$active === $key ? 'btn-primary btn-sm' : 'btn-ghost btn-sm bg-base-200'" />
    @endforeach
</div>
