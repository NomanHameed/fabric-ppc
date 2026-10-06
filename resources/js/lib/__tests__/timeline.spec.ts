import { afterEach, describe, expect, it, vi } from 'vitest';
import { formatTime, groupByDay, movement, toneFor } from '@/lib/timeline';
import type { TimelineEvent } from '@/types/analytics';

function event(overrides: Partial<TimelineEvent> = {}): TimelineEvent {
    return {
        id: 'movement:1',
        type: 'moved',
        title: 'Moved 100 m from Issued to Dyeing',
        occurred_at: '2026-09-20T09:30:00+05:00',
        from: 'Issued to floor',
        to: 'Dyeing',
        qty: 100,
        actor_id: 1,
        actor: 'Asif',
        note: null,
        ...overrides,
    };
}

describe('toneFor', () => {
    it('separates a loss from a dispatch, and falls back for an unknown type', () => {
        expect(toneFor('loss')).toBe('danger');
        expect(toneFor('dispatched')).toBe('success');
        expect(toneFor('something_new')).toBe('neutral');
    });
});

describe('groupByDay', () => {
    it('keeps the server order and opens a group per calendar day', () => {
        const days = groupByDay([
            event({ id: 'a', occurred_at: '2026-09-20T09:30:00+05:00' }),
            event({ id: 'b', occurred_at: '2026-09-20T17:05:00+05:00' }),
            event({ id: 'c', occurred_at: '2026-09-21T08:00:00+05:00' }),
        ]);

        expect(days).toHaveLength(2);
        expect(days[0].events.map((e) => e.id)).toEqual(['a', 'b']);
        expect(days[1].events.map((e) => e.id)).toEqual(['c']);
        expect(days[0].label).toMatch(/^\d{2}-\d{2}-\d{4}$/);
    });

    it('never drops an event whose timestamp will not parse', () => {
        const days = groupByDay([
            event({ id: 'x', occurred_at: 'not a date' }),
        ]);

        expect(days).toHaveLength(1);
        expect(days[0].events[0].id).toBe('x');
        expect(days[0].events[0].time).toBe('');
    });

    it('returns nothing for nothing', () => {
        expect(groupByDay([])).toEqual([]);
    });
});

/**
 * A lot's history belongs to the mill's calendar, not the reader's.
 *
 * The server sends every instant with its `+05:00` offset. Read back through the browser's own zone, an event
 * at 02:00 in Karachi is 21:00 the evening before in UTC — so a viewer abroad, or a CI box with no TZ set, saw
 * the night shift filed under the previous day and the clock four hours out. Both are the kind of quiet
 * wrongness a timeline exists to rule out, so the case is pinned with the viewer deliberately on UTC.
 */
describe("under a viewer whose browser is not on the mill's zone", () => {
    afterEach(() => {
        vi.unstubAllEnvs();
    });

    /** `TZ` is what Node resolves a Date's local getters against, so this really is "a viewer on UTC". */
    function onUtc<T>(run: () => T): T {
        vi.stubEnv('TZ', 'UTC');
        try {
            return run();
        } finally {
            vi.unstubAllEnvs();
        }
    }

    it("groups the night shift under the mill's day, not the viewer's", () => {
        const days = onUtc(() =>
            groupByDay([
                // 21:00 on the 20th in UTC; 02:00 on the 21st at the mill.
                event({
                    id: 'night',
                    occurred_at: '2026-09-21T02:00:00+05:00',
                }),
                event({
                    id: 'morning',
                    occurred_at: '2026-09-21T09:00:00+05:00',
                }),
            ]),
        );

        expect(days).toHaveLength(1);
        expect(days[0].key).toBe('21-09-2026');
        expect(days[0].events.map((e) => e.id)).toEqual(['night', 'morning']);
    });

    it('shows the time the clock on the mill wall showed', () => {
        expect(onUtc(() => formatTime('2026-09-21T02:00:00+05:00'))).toBe(
            '02:00',
        );
        // The same instant written in UTC must read the same: it is the same moment at the mill.
        expect(onUtc(() => formatTime('2026-09-20T21:00:00Z'))).toBe('02:00');
    });

    it('still keeps an unparseable timestamp out of the clock', () => {
        expect(onUtc(() => formatTime('not a date'))).toBe('');
    });
});

describe('movement', () => {
    const template = (r: { from: string; to: string }) => `${r.from} > ${r.to}`;

    it('shows both ends when the metres actually moved', () => {
        expect(movement(event(), template)).toBe('Issued to floor > Dyeing');
    });

    it('shows one place when the work happened where it stood', () => {
        expect(
            movement(event({ from: 'Dyeing', to: 'Dyeing' }), template),
        ).toBe('Dyeing');
        expect(movement(event({ from: null }), template)).toBe('Dyeing');
    });

    it('shows nothing for an event with no place at all', () => {
        expect(movement(event({ from: null, to: null }), template)).toBeNull();
    });
});
