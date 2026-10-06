/**
 * CSS custom properties → colours a canvas can paint.
 *
 * The Albeen palette is written in `oklch()` (see `resources/css/app.css`). ECharts hands its colour strings to
 * the canvas 2D context, and `oklch()` is only accepted there by fairly recent engines — an older one silently
 * paints black, which on a dashboard is worse than a slightly different green. The conversion is therefore done
 * here, in JS, where it is deterministic and unit-testable without a browser.
 *
 * Anything that is not `oklch()` (a hex, an `rgb()`, a keyword) is passed through untouched.
 */

/** sRGB gamma encoding (IEC 61966-2-1). */
function encode(channel: number): number {
    const c =
        channel <= 0.0031308
            ? 12.92 * channel
            : 1.055 * channel ** (1 / 2.4) - 0.055;
    return Math.min(255, Math.max(0, Math.round(c * 255)));
}

function hex2(value: number): string {
    return value.toString(16).padStart(2, '0');
}

/**
 * OKLCH → sRGB hex. `l` is 0–1, `c` is the chroma, `h` degrees.
 *
 * Out-of-gamut colours are clipped per channel, which is what a browser does for `oklch()` on a canvas too.
 */
export function oklchToHex(l: number, c: number, h: number): string {
    const hr = (h * Math.PI) / 180;
    const a = c * Math.cos(hr);
    const b = c * Math.sin(hr);

    const l_ = (l + 0.3963377774 * a + 0.2158037573 * b) ** 3;
    const m_ = (l - 0.1055613458 * a - 0.0638541728 * b) ** 3;
    const s_ = (l - 0.0894841775 * a - 1.291485548 * b) ** 3;

    const r = 4.0767416621 * l_ - 3.3077115913 * m_ + 0.2309699292 * s_;
    const g = -1.2684380046 * l_ + 2.6097574011 * m_ - 0.3413193965 * s_;
    const bl = -0.0041960863 * l_ - 0.7034186147 * m_ + 1.707614701 * s_;

    return `#${hex2(encode(r))}${hex2(encode(g))}${hex2(encode(bl))}`;
}

const OKLCH =
    /^oklch\(\s*([\d.]+%?)\s+([\d.]+%?)\s+([\d.]+)(?:deg)?\s*(?:\/\s*([\d.]+%?)\s*)?\)$/i;

function unit(value: string, full: number): number {
    return value.endsWith('%')
        ? (Number.parseFloat(value) / 100) * full
        : Number.parseFloat(value);
}

/**
 * A CSS colour ECharts can paint. `oklch()` is converted; everything else is returned as it came in, trimmed.
 * An `oklch()` with an alpha keeps it as an `rgba()`, because a hex would drop it.
 */
export function toCanvasColour(value: string): string {
    const raw = value.trim();
    const match = OKLCH.exec(raw);
    if (match === null) {
        return raw;
    }
    const l = unit(match[1], 1);
    const c = unit(match[2], 0.4);
    const h = Number.parseFloat(match[3]);
    const hex = oklchToHex(l, c, Number.isFinite(h) ? h : 0);
    if (match[4] === undefined) {
        return hex;
    }
    const alpha = unit(match[4], 1);
    const [r, g, b] = [1, 3, 5].map((i) =>
        Number.parseInt(hex.slice(i, i + 2), 16),
    );
    return `rgba(${r}, ${g}, ${b}, ${Math.min(1, Math.max(0, alpha))})`;
}

/** The same colour at a given opacity, for fills under a line or a capacity ghost bar. */
export function withAlpha(colour: string, alpha: number): string {
    const hex = toCanvasColour(colour);
    if (!/^#[0-9a-f]{6}$/i.test(hex)) {
        return hex;
    }
    const [r, g, b] = [1, 3, 5].map((i) =>
        Number.parseInt(hex.slice(i, i + 2), 16),
    );
    return `rgba(${r}, ${g}, ${b}, ${alpha})`;
}

/**
 * Read one custom property off an element (the document root by default) and make it paintable.
 * Returns `fallback` when the property is missing — during SSR, or before the stylesheet has applied.
 */
export function readCssColour(
    name: string,
    fallback: string,
    root?: Element | null,
): string {
    if (typeof window === 'undefined') {
        return fallback;
    }
    const element = root ?? document.documentElement;
    const value = window.getComputedStyle(element).getPropertyValue(name);
    return value.trim() === '' ? fallback : toCanvasColour(value);
}
