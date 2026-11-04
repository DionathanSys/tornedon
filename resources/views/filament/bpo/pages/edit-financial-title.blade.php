<x-filament-panels::page class="bpo-workbench-page bpo-account-detail bpo-account-edit">
    @php($account = $this->relationshipDetails())

    <div class="bpo-account-top-grid">
        <div class="bpo-form-content">{{ $this->content }}</div>
        <aside class="bpo-account-attachments" aria-label="Anexos do lançamento">
            @livewire(\App\Livewire\Bpo\RecordAttachments::class, ['module' => $account['module'], 'recordId' => $account['id']], key('account-attachments-'.$account['module'].'-'.$account['id']))
        </aside>
    </div>

    @include('filament.bpo.partials.account-relationships', ['account' => $account])
</x-filament-panels::page>
