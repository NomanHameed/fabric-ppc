import { describe, expect, it, vi } from 'vitest';
import {
    presetRange,
    useRangeFilters,
} from '@/components/charts/useRangeFilters';

describe('presetRange', () => {
    const today = new Date(2026, 8, 24); // 24 Sep 2026, local

    it('counts the last seven days inclusive, not eight', () => {
        expect(presetRange('week', today)).toEqual({
            from: '2026-09-18',
            to: '2026-09-24',
        });
    });

    it('starts this month on the first', () => {
        expect(presetRange('month', today)).toEqual({
            from: '2026-09-01',
            to: '2026-09-24',
        });
    });

    it('walks 90 days back across a month boundary', () => {
        expect(presetRange('quarter', today)).toEqual({
            from: '2026-06-27',
            to: '2026-09-24',
        });
    });
});

describe('useRangeFilters', () => {
    function withSearch<T>(search: string, run: () => T): T {
        const original = window.location.search;
        window.history.replaceState({}, '', `/dashboard${search}`);
        try {
            return run();
        } finally {
            window.history.replaceState({}, '', `/dashboard${original}`);
        }
    }

    it('takes a valid range out of the url so a refresh keeps it', () => {
        const { filters } = withSearch(
            '?from=2026-01-01&to=2026-02-01&department_id=4',
            () => useRangeFilters(),
        );

        expect(filters.from).toBe('2026-01-01');
        expect(filters.to).toBe('2026-02-01');
        expect(filters.department_id).toBe('4');
    });

    it('ignores rubbish in the url rather than sending it to the server', () => {
        const { filters } = withSearch(
            '?from=yesterday&department_id=%27+or+1%3D1',
            () => useRangeFilters(),
        );

        expect(filters.from).toMatch(/^\d{4}-\d{2}-\d{2}$/);
        expect(filters.department_id).toBe('');
    });

    it('straightens a backwards range instead of asking the server to reject it', () => {
        const { filters } = withSearch('?from=2026-05-01&to=2026-01-01', () =>
            useRangeFilters(),
        );
        expect(filters.to).toBe('2026-05-01');

        const later = withSearch('', () => useRangeFilters());
        later.filters.from = '2026-06-01';
        later.filters.to = '2026-01-01';
        later.apply();
        expect(later.filters.to).toBe('2026-06-01');
    });

    it('builds a query with only the filters that are set', () => {
        const range = withSearch('?from=2026-01-01&to=2026-01-31', () =>
            useRangeFilters(),
        );

        expect(range.query()).toBe('from=2026-01-01&to=2026-01-31');
        range.filters.operation_id = '9';
        expect(range.query()).toBe(
            'from=2026-01-01&to=2026-01-31&operation_id=9',
        );
    });

    it('drops the dimension filters entirely for a screen that has none', () => {
        const range = withSearch('?department_id=3', () =>
            useRangeFilters({ dimensions: false }),
        );
        expect(range.query()).not.toContain('department_id');
    });

    it('writes the applied filters back into the url', () => {
        const replace = vi.spyOn(window.history, 'replaceState');
        const range = withSearch('', () => useRangeFilters());
        range.filters.from = '2026-03-01';
        range.filters.to = '2026-03-31';
        range.apply();

        expect(replace).toHaveBeenCalledWith(
            expect.anything(),
            '',
            expect.stringContaining('from=2026-03-01&to=2026-03-31'),
        );
        replace.mockRestore();
    });
});
