export const acceptedMediaFileTypes = [
    '.jpg',
    '.jpeg',
    '.png',
    '.gif',
    '.webp',
    '.svg',
    '.bmp',
    '.avif',
    '.ico',
    '.mp4',
    '.webm',
    '.mov',
    '.mp3',
    '.wav',
    '.m4a',
    '.ogg',
].join(',');

export const maxMediaFileBytes = 100 * 1024 * 1024;
export const maxMediaUploadBytes = 100 * 1024 * 1024;
export const maxMediaFiles = 10;

function fileMimeType(file) {
    return String(file?.mime_type ?? file?.type ?? '').toLowerCase();
}

function fileName(file) {
    return String(file?.name ?? '').toLowerCase();
}

export function isImageFile(file) {
    return (
        fileMimeType(file).startsWith('image/') ||
        /\.(jpg|jpeg|png|gif|webp|svg|bmp|avif|ico)$/.test(fileName(file))
    );
}

export function isVideoFile(file) {
    return fileMimeType(file).startsWith('video/') || /\.(mp4|webm|mov)$/.test(fileName(file));
}

export function isAudioFile(file) {
    return fileMimeType(file).startsWith('audio/') || /\.(mp3|wav|m4a|ogg)$/.test(fileName(file));
}

export function isAllowedMediaFile(file) {
    return isImageFile(file) || isVideoFile(file) || isAudioFile(file);
}
