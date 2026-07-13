@include('filament.partials.body-end')

<script>
    (() => {
        const selectors = [
            'input:not([type="hidden"]):not([disabled])',
            'textarea:not([disabled])',
            'select:not([disabled])',
            '[contenteditable="true"]',
        ].join(',');

        if (! window.visualViewport) {
            return;
        }

        let activeField = null;
        let scrollTimer = null;

        const scheduleScroll = () => {
            if (! activeField || ! document.contains(activeField)) {
                return;
            }

            window.clearTimeout(scrollTimer);

            scrollTimer = window.setTimeout(() => {
                if (! activeField || ! document.contains(activeField)) {
                    return;
                }

                const viewportHeight = window.visualViewport.height;

                if (viewportHeight >= window.innerHeight - 80) {
                    return;
                }

                const rect = activeField.getBoundingClientRect();
                const padding = 24;
                const visibleBottom = viewportHeight - padding;

                if (rect.bottom <= visibleBottom && rect.top >= padding) {
                    return;
                }

                activeField.scrollIntoView({
                    block: 'nearest',
                    inline: 'nearest',
                    behavior: 'smooth',
                });
            }, 180);
        };

        document.addEventListener('focusin', (event) => {
            const target = event.target;

            if (! (target instanceof HTMLElement) || ! target.matches(selectors)) {
                return;
            }

            activeField = target;
            scheduleScroll();
        });

        document.addEventListener('focusout', (event) => {
            if (event.target === activeField) {
                activeField = null;
            }
        });

        window.visualViewport.addEventListener('resize', scheduleScroll);
        window.visualViewport.addEventListener('scroll', scheduleScroll);
    })();
</script>
