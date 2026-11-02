import { operationSignatureCapture } from './operation-signature';
import { operationFab } from './operation-fab';
import { operationPhotoUpload } from './operation-photo-upload';
import { createOperationPwaInstaller } from './operation-pwa';

window.operationSignatureCapture = operationSignatureCapture;
window.operationFab = operationFab;
window.operationPhotoUpload = operationPhotoUpload;
window.operationPwaInstall = createOperationPwaInstaller(window);
