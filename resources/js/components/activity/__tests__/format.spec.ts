import { afterEach, describe, expect, it, vi } from 'vitest';
import { formatDate, formatDateTime } from '@/components/activity/format';

/*
 * The timestamps the approval queue and the action log are read by.
 *
 * An action log exists to settle what happened and when. If its clock depends on where the reader's browser
 * thinks it is, it settles nothing: the server sends every instant with a `+05:00` offset, and read back
 * through the browser's own getters, a signup recorded at 02:00 in Karachi showed as 21:00 the previous
 * evening to anyone on UTC — a different date, on a screen whose whole purpose is the date. So the case is
 * pinned with the viewer deliberately somewhere else.
 */
afterEach(() => {
    vi.unstubAllEnvs();
});

/** `TZ` is what Node resolves a Date's local getters against, so this really is "a viewer over there". */
function inZone<T>(zone: string, run: () => T): T {
    vi.stubEnv('TZ', zone);
    try {
        return run();
    } finally {
        vi.unstubAllEnvs();
    }
}

describe('formatDateTime', () => {
    it("reads the mill's clock, whatever zone the viewer is in", () => {
        const midnightShift = '2026-09-24T02:15:00+05:00';

        expect(inZone('UTC', () => formatDateTime(midnightShift))).toBe(
            '24-09-2026 02:15',
        );
        expect(
            inZone('America/New_York', () => formatDateTime(midnightShift)),
        ).toBe('24-09-2026 02:15');
        expect(
            inZone('Asia/Karachi', () => formatDateTime(midnightShift)),
        ).toBe('24-09-2026 02:15');
    });

    it('resolves the same instant written any way to the same reading', () => {
        // 21:15 UTC on the 23rd is 02:15 on the 24th at the mill.
        expect(
            inZone('UTC', () => formatDateTime('2026-09-23T21:15:00Z')),
        ).toBe('24-09-2026 02:15');
        expect(
            inZone('UTC', () => formatDateTime('2026-09-23T17:15:00-04:00')),
        ).toBe('24-09-2026 02:15');
    });

    it('keeps the day the mill worked, not the day the viewer was on', () => {
        // 23:30 at the mill is still the 24th; on UTC the same instant is 18:30 on the 24th too, but an
        // evening event on a westward zone would otherwise slide backwards a day.
        expect(
            inZone('Pacific/Auckland', () =>
                formatDateTime('2026-09-24T23:30:00+05:00'),
            ),
        ).toBe('24-09-2026 23:30');
    });

    it('uses a 24-hour clock and Latin digits', () => {
        expect(formatDateTime('2026-01-05T18:07:00+05:00')).toBe(
            '05-01-2026 18:07',
        );
        expect(formatDateTime('2026-01-05T00:00:00+05:00')).toBe(
            '05-01-2026 00:00',
        );
    });

    it('says nothing rather than something wrong for a missing or unusable value', () => {
        expect(formatDateTime(null)).toBe('');
        expect(formatDateTime(undefined)).toBe('');
        expect(formatDateTime('')).toBe('');
        expect(formatDateTime('not a date')).toBe('');
        expect(formatDateTime('2026-13-45T99:99:99+05:00')).toBe('');
    });
});

describe('formatDate', () => {
    it("gives the mill's calendar day, in the app's dd-mm-yyyy", () => {
        expect(
            inZone('UTC', () => formatDate('2026-09-24T02:15:00+05:00')),
        ).toBe('24-09-2026');
        expect(formatDate('2026-09-24')).toBe('24-09-2026');
    });

    it('guards the same empty and unparseable cases', () => {
        expect(formatDate(null)).toBe('');
        expect(formatDate(undefined)).toBe('');
        expect(formatDate('')).toBe('');
        expect(formatDate('not a date')).toBe('');
    });
});
