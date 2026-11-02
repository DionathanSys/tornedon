<script data-navigate-once>
    if ('serviceWorker' in navigator && window.isSecureContext) {
        const registerServiceWorker = () => {
            navigator.serviceWorker.register('/sw.js', { scope: '/', updateViaCache: 'none' })
                .catch(error => console.error('Não foi possível registrar o service worker.', error));
        };

        if (document.readyState === 'complete') {
            registerServiceWorker();
        } else {
            window.addEventListener('load', registerServiceWorker, { once: true });
        }
    }
</script>
