import { describe, expect, it } from 'vitest';
import {
    oklchToHex,
    readCssColour,
    toCanvasColour,
    withAlpha,
} from '@/components/charts/colour';

describe('oklchToHex', () => {
    it('converts the Albeen brand green back to the hex it was sampled from', () => {
        // docs/planning/theme.md: the logo green #44916D was recorded as oklch(0.5974 0.0952 161.2).
        expect(oklchToHex(0.5974, 0.0952, 161.2)).toBe('#44916d');
    });

    it('converts the logo grey', () => {
        // #848283 → oklch(0.6087 0.0028 345.32); a 1/255 drift either way is invisible, so allow one step.
        const hex = oklchToHex(0.6087, 0.0028, 345.32);
        const [r, g, b] = [1, 3, 5].map((i) =>
            Number.parseInt(hex.slice(i, i + 2), 16),
        );
        expect(Math.abs(r - 0x84)).toBeLessThanOrEqual(1);
        expect(Math.abs(g - 0x82)).toBeLessThanOrEqual(1);
        expect(Math.abs(b - 0x83)).toBeLessThanOrEqual(1);
    });

    it('clips out-of-gamut colours to black and white rather than producing NaN', () => {
        expect(oklchToHex(0, 0, 0)).toBe('#000000');
        expect(oklchToHex(1, 0, 0)).toBe('#ffffff');
        expect(oklchToHex(0.99, 0.4, 140)).toMatch(/^#[0-9a-f]{6}$/);
    });
});

describe('toCanvasColour', () => {
    it('leaves anything that is not oklch() alone', () => {
        expect(toCanvasColour('#112233')).toBe('#112233');
        expect(toCanvasColour('  rgb(1, 2, 3) ')).toBe('rgb(1, 2, 3)');
        expect(toCanvasColour('transparent')).toBe('transparent');
    });

    it('reads percentages and a slash alpha', () => {
        expect(toCanvasColour('oklch(59.74% 0.0952 161.2)')).toBe('#44916d');
        expect(toCanvasColour('oklch(0.5974 0.0952 161.2 / 0.5)')).toBe(
            'rgba(68, 145, 109, 0.5)',
        );
    });

    it('survives a value the stylesheet has not produced yet', () => {
        expect(toCanvasColour('')).toBe('');
        expect(toCanvasColour('oklch(broken)')).toBe('oklch(broken)');
    });
});

describe('withAlpha', () => {
    it('turns a token colour into a translucent fill', () => {
        expect(withAlpha('oklch(0.5974 0.0952 161.2)', 0.12)).toBe(
            'rgba(68, 145, 109, 0.12)',
        );
    });

    it('passes a non-hex colour straight through instead of guessing', () => {
        expect(withAlpha('rgb(1, 2, 3)', 0.5)).toBe('rgb(1, 2, 3)');
    });
});

describe('readCssColour', () => {
    it('reads a custom property off an element and converts it', () => {
        const el = document.createElement('div');
        el.style.setProperty('--probe', 'oklch(0.5974 0.0952 161.2)');
        document.body.append(el);

        expect(readCssColour('--probe', '#000000', el)).toBe('#44916d');

        el.remove();
    });

    it('falls back when the property is not set', () => {
        expect(readCssColour('--nothing-here', '#abcdef')).toBe('#abcdef');
    });
});
