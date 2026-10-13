const photoTypes = ['image/jpeg', 'image/png', 'image/webp'];
const reductionThreshold = 5 * 1024 * 1024;

export function photoDimensions(width, height, maxEdge = 1920) {
    const scale = Math.min(1, maxEdge / Math.max(width, height));
    return { width: Math.max(1, Math.round(width * scale)), height: Math.max(1, Math.round(height * scale)) };
}

function readImage(file) {
    return new Promise((resolve, reject) => {
        const url = URL.createObjectURL(file);
        const image = new Image();
        image.onload = () => { URL.revokeObjectURL(url); resolve(image); };
        image.onerror = () => { URL.revokeObjectURL(url); reject(new Error('Não foi possível abrir a imagem para reduzi-la.')); };
        image.src = url;
    });
}

export async function reduceServicePhoto(file, maxBytes) {
    const image = await readImage(file);
    const canvas = document.createElement('canvas');
    const context = canvas.getContext('2d');
    if (!context) throw new Error('Seu navegador não permite reduzir esta imagem.');

    let maxEdge = 1920;
    let quality = 0.82;
    for (let attempt = 0; attempt < 8; attempt++) {
        const size = photoDimensions(image.naturalWidth, image.naturalHeight, maxEdge);
        canvas.width = size.width;
        canvas.height = size.height;
        context.fillStyle = '#ffffff';
        context.fillRect(0, 0, canvas.width, canvas.height);
        context.drawImage(image, 0, 0, canvas.width, canvas.height);
        const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', quality));
        if (!blob) throw new Error('Não foi possível reduzir esta imagem.');
        if (blob.size <= maxBytes && blob.size < file.size) {
            return new File([blob], file.name.replace(/\.[^.]+$/, '') + '-reduzida.jpg', { type: 'image/jpeg', lastModified: file.lastModified });
        }
        quality = Math.max(0.5, quality - 0.12);
        maxEdge = Math.round(maxEdge * 0.8);
    }
    throw new Error('Não foi possível reduzir a foto até o limite permitido.');
}

export async function prepareServicePhotos(files, { type, maxBytes, confirm = message => window.confirm(message), reduce = reduceServicePhoto }) {
    const prepared = [];
    const messages = [];
    for (const file of files) {
        let candidate = file;
        if (type === 'service_photo' && photoTypes.includes(file.type) && file.size > Math.min(maxBytes, reductionThreshold)) {
            const accepted = await confirm(`A foto “${file.name}” tem ${(file.size / 1024 / 1024).toFixed(1)} MB. Deseja reduzir o tamanho para anexar este arquivo? A cópia será convertida para JPEG, com resolução e qualidade reduzidas. O original no seu dispositivo será mantido.`);
            if (accepted) {
                try {
                    candidate = await reduce(file, Math.min(maxBytes, reductionThreshold));
                    messages.push(`${file.name}: reduzida de ${(file.size / 1024 / 1024).toFixed(1)} MB para ${(candidate.size / 1024 / 1024).toFixed(1)} MB.`);
                } catch (error) {
                    messages.push(`${file.name}: ${error.message}`);
                    continue;
                }
            }
        }
        if (candidate.size > maxBytes) {
            messages.push(`${file.name}: não enviado. O limite é ${(maxBytes / 1024 / 1024).toFixed(0)} MB por arquivo.`);
            continue;
        }
        prepared.push(candidate);
    }
    return { files: prepared, messages };
}

export function operationPhotoUpload(limits) {
    return {
        busy: false,
        progress: 0,
        messages: [],
        async selectFiles(event) {
            const files = Array.from(event.target.files ?? []);
            event.target.value = '';
            if (!files.length || this.busy) return;
            this.messages = [];
            if (files.length > 10) {
                this.messages = ['Envie até 10 arquivos por vez.'];
                return;
            }
            this.busy = true;
            this.progress = 0;
            try {
                const result = await prepareServicePhotos(files, {
                    type: this.$wire.attachmentType,
                    maxBytes: limits[this.$wire.attachmentType] ?? 20 * 1024 * 1024,
                });
                this.messages = result.messages;
                // Replace the selection, including when every file was declined or rejected.
                await this.$wire.set('files', []);
                if (!result.files.length) return;
                await new Promise((resolve, reject) => this.$wire.uploadMultiple('files', result.files, resolve,
                    () => reject(new Error('Não foi possível enviar os arquivos. Confira a conexão e tente novamente.')),
                    event => { this.progress = event.detail.progress; },
                    () => reject(new Error('Envio cancelado.')),
                ));
            } catch (error) {
                this.messages.push(error.message);
            } finally {
                this.busy = false;
            }
        },
    };
}
