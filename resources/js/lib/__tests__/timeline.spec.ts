import { describe, expect, it } from 'vitest';
import { groupByDay, movement, toneFor } from '@/lib/timeline';
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
