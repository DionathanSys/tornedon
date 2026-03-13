<div
    x-data="{
        open: false,
        panelStyle: 'display: none;',
        openPanel() {
            this.open = true

            this.$nextTick(() => {
                const rect = this.$refs.trigger.getBoundingClientRect()
                const width = 320
                const gap = 14
                const viewportPadding = 8
                const maxLeft = window.innerWidth - width - viewportPadding
                const left = Math.max(viewportPadding, Math.min(rect.left, maxLeft))
                const top = rect.bottom + gap

                this.panelStyle = `position: fixed; top: ${top}px; left: ${left}px; width: ${width}px; z-index: 9999; display: block;`
            })
        },
        closePanel() {
            this.open = false
            this.panelStyle = 'display: none;'
        },
    }"
    @keydown.escape.window="closePanel()"
    @scroll.window.throttle.50ms="open && openPanel()"
    @resize.window.throttle.50ms="open && openPanel()"
    class="relative inline-flex items-center overflow-visible"
>
    <button
        x-ref="trigger"
        type="button"
        @click="open ? closePanel() : openPanel()"
        class="inline-flex h-5 w-5 items-center justify-center rounded-full text-gray-500 transition hover:bg-gray-100 hover:text-primary-600"
        aria-label="Abrir ajuda do campo"
    >
        <x-filament::icon icon="heroicon-m-question-mark-circle" class="h-5 w-5" />
    </button>

    <template x-teleport="body">
        <div
            x-cloak
            x-show="open"
            x-transition
            @click.outside="closePanel()"
            class="p-6 shadow-xl"
            x-bind:style="`${panelStyle}; border-radius: 14px; m-6; p-6; background-color: #eff6ff; border: 1px solid #bfdbfe; box-shadow: 0 10px 30px rgba(15, 23, 42, 0.15); font-family: 'Nunito', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;`"
        >
            <div class="mb-2 text-sm font-semibold text-gray-900">{{ $title }}</div>
            <div class="text-sm m-6 p-6 leading-6 text-gray-700">{{ $content }}</div>
        </div>
    </template>
</div>
