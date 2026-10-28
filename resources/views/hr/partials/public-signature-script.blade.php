<script>
(() => {
    const canvas = document.getElementById('signature-pad');
    const form = document.getElementById('hr-receipt-signature-form');
    const input = document.getElementById('signature');
    const context = canvas.getContext('2d');
    let drawing = false, pointerId = null, hasSignature = false;
    const resize = () => {
        const previous = hasSignature ? canvas.toDataURL('image/png') : null;
        const rect = canvas.getBoundingClientRect();
        const ratio = Math.min(Math.max(window.devicePixelRatio || 1, 1), 2);
        canvas.width = Math.max(Math.floor(rect.width * ratio), 1);
        canvas.height = Math.max(Math.floor(rect.height * ratio), 1);
        context.setTransform(ratio, 0, 0, ratio, 0, 0);
        context.lineCap = 'round'; context.lineJoin = 'round'; context.lineWidth = 2.2; context.strokeStyle = '#17212b';
        if (previous) {
            const image = new Image();
            image.onload = () => { context.drawImage(image, 0, 0, rect.width, rect.height); input.value = canvas.toDataURL('image/png'); };
            image.src = previous;
        }
    };
    const point = event => { const rect = canvas.getBoundingClientRect(); return { x: event.clientX - rect.left, y: event.clientY - rect.top }; };
    canvas.addEventListener('pointerdown', event => {
        event.preventDefault(); drawing = true; pointerId = event.pointerId; canvas.setPointerCapture?.(pointerId);
        const p = point(event); context.beginPath(); context.moveTo(p.x, p.y); context.arc(p.x, p.y, 1.2, 0, Math.PI * 2); context.fillStyle = '#17212b'; context.fill(); hasSignature = true;
    });
    canvas.addEventListener('pointermove', event => { if (!drawing || event.pointerId !== pointerId) return; event.preventDefault(); const p = point(event); context.lineTo(p.x, p.y); context.stroke(); });
    const finish = event => {
        if (!drawing || event.pointerId !== pointerId) return;
        drawing = false; canvas.releasePointerCapture?.(pointerId); pointerId = null; context.closePath(); input.value = canvas.toDataURL('image/png');
    };
    canvas.addEventListener('pointerup', finish); canvas.addEventListener('pointercancel', finish);
    document.getElementById('clear-signature').addEventListener('click', () => { context.clearRect(0, 0, canvas.clientWidth, canvas.clientHeight); hasSignature = false; input.value = ''; });
    form.addEventListener('submit', event => {
        if (!hasSignature) { event.preventDefault(); alert('Faça sua assinatura antes de confirmar.'); return; }
        input.value = canvas.toDataURL('image/png'); document.getElementById('submit-signature').disabled = true;
    });
    window.addEventListener('resize', resize); resize();
})();
</script>
