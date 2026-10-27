/**
 * Smart checks for a photo of a signed form, run on this device only.
 *
 * Plain image measurements, no AI and nothing sent anywhere:
 * - brightness: average light level (0 dark - 255 bright);
 * - contrast: how far light and dark parts differ (standard deviation);
 * - sharpness: variance of the Laplacian (edges); blurry photos score low;
 * - size: the shorter side in pixels; small photos are hard to read.
 * The thresholds are practical guides, tuned on phone photos of A4 forms.
 */

export type PhotoIssue = 'dark' | 'bright' | 'low_contrast' | 'blurry' | 'small';

export type PhotoReport = {
    brightness: number;
    contrast: number;
    sharpness: number;
    shortSide: number;
    issues: PhotoIssue[];
    /** True when no issue was found. */
    good: boolean;
};

export const LIMITS = { dark: 70, bright: 230, lowContrast: 28, blurry: 40, small: 700 } as const;

export const ISSUE_TEXT: Record<PhotoIssue, string> = {
    dark: 'The photo is too dark. Move to better light or turn on a light, then take it again.',
    bright: 'The photo is too bright or has glare. Tilt the paper away from the light.',
    low_contrast: 'The writing is faint. Try "Make the text clearer" or take it again in better light.',
    blurry: 'The photo looks blurry. Hold the phone still, let it focus, then take it again.',
    small: 'The photo is small. Move closer so the form fills the frame.',
};

/** Luminance (0-255) for each pixel of RGBA data. */
export function toGray(data: Uint8ClampedArray | number[], width: number, height: number): Float32Array {
    const gray = new Float32Array(width * height);
    for (let i = 0, p = 0; p < gray.length; i += 4, p++) {
        gray[p] = 0.299 * data[i] + 0.587 * data[i + 1] + 0.114 * data[i + 2];
    }
    return gray;
}

/** Brightness, contrast and sharpness of a grayscale image. */
export function measure(gray: Float32Array, width: number, height: number): { brightness: number; contrast: number; sharpness: number } {
    let sum = 0;
    for (const value of gray) sum += value;
    const brightness = gray.length ? sum / gray.length : 0;
    let squares = 0;
    for (const value of gray) squares += (value - brightness) ** 2;
    const contrast = gray.length ? Math.sqrt(squares / gray.length) : 0;
    // Laplacian: 4*centre - up - down - left - right.
    let lapSum = 0;
    let lapSquares = 0;
    let count = 0;
    for (let y = 1; y < height - 1; y++) {
        for (let x = 1; x < width - 1; x++) {
            const i = y * width + x;
            const lap = 4 * gray[i] - gray[i - 1] - gray[i + 1] - gray[i - width] - gray[i + width];
            lapSum += lap;
            lapSquares += lap * lap;
            count++;
        }
    }
    const mean = count ? lapSum / count : 0;
    const sharpness = count ? lapSquares / count - mean * mean : 0;
    return { brightness, contrast, sharpness };
}

/** Turn measurements into the list of problems to show the worker. */
export function assess(values: { brightness: number; contrast: number; sharpness: number; shortSide: number }): PhotoReport {
    const issues: PhotoIssue[] = [];
    if (values.brightness < LIMITS.dark) issues.push('dark');
    else if (values.brightness > LIMITS.bright) issues.push('bright');
    if (values.contrast < LIMITS.lowContrast && !issues.includes('dark')) issues.push('low_contrast');
    if (values.sharpness < LIMITS.blurry) issues.push('blurry');
    if (values.shortSide < LIMITS.small) issues.push('small');
    return { ...values, issues, good: issues.length === 0 };
}

/**
 * "Make the text clearer": black-and-white with the contrast stretched
 * between the 2nd and 98th percentile, like a document scanner.
 */
export function enhance(data: Uint8ClampedArray, width: number, height: number): void {
    const gray = toGray(data, width, height);
    const histogram = new Uint32Array(256);
    for (const value of gray) histogram[Math.min(255, Math.max(0, Math.round(value)))]++;
    const percentile = (fraction: number) => {
        const target = gray.length * fraction;
        let seen = 0;
        for (let level = 0; level < 256; level++) {
            seen += histogram[level];
            if (seen >= target) return level;
        }
        return 255;
    };
    const low = percentile(0.02);
    const high = Math.max(low + 1, percentile(0.98));
    const scale = 255 / (high - low);
    for (let i = 0, p = 0; p < gray.length; i += 4, p++) {
        const value = Math.min(255, Math.max(0, (gray[p] - low) * scale));
        data[i] = data[i + 1] = data[i + 2] = value;
    }
}

/** Measure a canvas (downscaled to at most 480 px so it stays fast on older phones). */
export function checkCanvas(canvas: HTMLCanvasElement): PhotoReport {
    const shortSide = Math.min(canvas.width, canvas.height);
    const scale = Math.min(1, 480 / Math.max(canvas.width, canvas.height));
    const width = Math.max(1, Math.round(canvas.width * scale));
    const height = Math.max(1, Math.round(canvas.height * scale));
    const small = document.createElement('canvas');
    small.width = width;
    small.height = height;
    const context = small.getContext('2d', { willReadFrequently: true });
    if (!context) return assess({ brightness: 128, contrast: 60, sharpness: 100, shortSide });
    context.drawImage(canvas, 0, 0, width, height);
    const gray = toGray(context.getImageData(0, 0, width, height).data, width, height);
    return assess({ ...measure(gray, width, height), shortSide });
}
