export function createOperationPwaInstaller(browser) {
    const displayMode = browser.matchMedia('(display-mode: standalone)');
    const ios = /iPad|iPhone|iPod/.test(browser.navigator.userAgent)
        || (browser.navigator.platform === 'MacIntel' && browser.navigator.maxTouchPoints > 1);
    const subscribers = new Set();
    let prompt = null;
    let installed = false;
    let dismissed = false;
    let instructions = false;
    let installing = false;

    const snapshot = () => ({
        available: browser.isSecureContext && !installed && !displayMode.matches
            && !browser.navigator.standalone && (prompt !== null || ios),
        dismissed,
        instructions,
        installing,
    });
    const notify = () => subscribers.forEach(update => update(snapshot()));

    browser.addEventListener('beforeinstallprompt', event => {
        event.preventDefault();
        prompt = event;
        notify();
    });
    browser.addEventListener('appinstalled', () => {
        installed = true;
        prompt = null;
        notify();
    });
    displayMode.addEventListener('change', notify);

    return () => ({
        ...snapshot(),
        init() {
            this.updateInstallState = state => Object.assign(this, state);
            subscribers.add(this.updateInstallState);
            this.updateInstallState(snapshot());
        },
        destroy() {
            subscribers.delete(this.updateInstallState);
        },
        dismiss() {
            dismissed = true;
            instructions = false;
            notify();
        },
        async install() {
            if (!snapshot().available || installing) return;
            if (!prompt) {
                dismissed = false;
                instructions = true;
                notify();
                return;
            }

            const deferredPrompt = prompt;
            installing = true;
            notify();
            try {
                await deferredPrompt.prompt();
                await deferredPrompt.userChoice;
            } catch (error) {
                browser.console.error('Não foi possível abrir a instalação do aplicativo.', error);
            } finally {
                // A beforeinstallprompt event can only be used once, even if declined.
                if (prompt === deferredPrompt) prompt = null;
                installing = false;
                notify();
            }
        },
    });
}
