import { describe, expect, it } from 'vitest';
import { formatDmy, formatQty, todayDmy } from '@/lib/dates';

describe('formatDmy', () => {
    it('pads day and month to two digits', () => {
        expect(formatDmy(new Date(2026, 8, 3))).toBe('03-09-2026');
        expect(formatDmy(new Date(2026, 11, 25))).toBe('25-12-2026');
    });

    it('todayDmy matches the dd-mm-yyyy shape', () => {
        expect(todayDmy()).toMatch(/^\d{2}-\d{2}-\d{4}$/);
    });
});

describe('formatQty', () => {
    it('renders two decimals and blanks what is not a number', () => {
        expect(formatQty(120.5)).toBe('120.50');
        expect(formatQty('3627')).toBe('3627.00');
        expect(formatQty(null)).toBe('0.00');
        expect(formatQty('abc')).toBe('');
        expect(formatQty(undefined)).toBe('');
    });
});
