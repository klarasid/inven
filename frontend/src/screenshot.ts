/** What a screenshot is sent at, at most (Klaras Panel's FeedbackAttachments::TARGET_BYTES, and Feedback::MAX_SCREENSHOT_BYTES here). */
export const SCREENSHOT_TARGET_BYTES = 512000;

/** The longest side a shrunk screenshot keeps: still legible at full-HD. */
const MAX_SIDE = 1920;

/** Larger than this is not even tried: no screenshot is this big. */
export const SCREENSHOT_MAX_INPUT_BYTES = 25 * 1024 * 1024;

export const SCREENSHOT_TYPES = ['image/png', 'image/jpeg', 'image/webp'];

function encode(
    canvas: HTMLCanvasElement,
    type: string,
    quality: number,
): Promise<Blob | null> {
    return new Promise((resolve) => canvas.toBlob(resolve, type, quality));
}

/**
 * The screenshot as sent: as it is when small enough, otherwise redrawn no
 * longer than 1920 px as a WebP (a JPEG where the browser cannot write WebP)
 * at falling quality, then smaller, until it is at most 500 KB. A browser
 * that cannot redraw it gets it back as it was, and it is refused if still too large.
 */
export async function shrinkScreenshot(file: File): Promise<File> {
    if (
        file.size <= SCREENSHOT_TARGET_BYTES ||
        typeof createImageBitmap !== 'function'
    ) {
        return file;
    }

    let bitmap: ImageBitmap;
    try {
        bitmap = await createImageBitmap(file);
    } catch {
        return file;
    }

    const longest = Math.max(bitmap.width, bitmap.height);
    let side = Math.min(MAX_SIDE, longest);
    let smallest: Blob | null = null;

    search: for (let round = 0; round < 4; round++) {
        const scale = Math.min(1, side / longest);
        const canvas = document.createElement('canvas');
        canvas.width = Math.max(1, Math.round(bitmap.width * scale));
        canvas.height = Math.max(1, Math.round(bitmap.height * scale));
        const context = canvas.getContext('2d');
        if (!context) {
            break;
        }
        // JPEG has no transparency: white, as a page would be.
        context.fillStyle = '#fff';
        context.fillRect(0, 0, canvas.width, canvas.height);
        context.drawImage(bitmap, 0, 0, canvas.width, canvas.height);

        for (const quality of [0.8, 0.65, 0.5]) {
            let blob = await encode(canvas, 'image/webp', quality);
            if (!blob || blob.type !== 'image/webp') {
                blob = await encode(canvas, 'image/jpeg', quality);
            }
            if (blob && (!smallest || blob.size < smallest.size)) {
                smallest = blob;
            }
            if (blob && blob.size <= SCREENSHOT_TARGET_BYTES) {
                break search;
            }
        }
        side = Math.round(side * 0.75);
    }
    bitmap.close();

    if (!smallest || smallest.size >= file.size) {
        return file;
    }
    const extension = smallest.type === 'image/webp' ? 'webp' : 'jpg';

    return new File(
        [smallest],
        `${file.name.replace(/\.[^.]+$/, '') || 'tangkapan-layar'}.${extension}`,
        { type: smallest.type },
    );
}
