<header class="bpo-page-heading bpo-workbench-page-heading">
    <div>
        <span class="bpo-eyebrow">{{ $this->workbenchEyebrow() }}</span>
        <h1>{{ $this->getHeading() }}</h1>
        <p>{{ $this->workbenchDescription() }}</p>
    </div>
    <div class="bpo-account-actions">
        <x-filament::actions :actions="$this->getCachedHeaderActions()" />
    </div>
</header>
