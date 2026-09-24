import { describe, expect, it } from 'vitest';
import { NAVIGATION, visibleNavigation } from '@/config/navigation';

describe('visibleNavigation', () => {
    it('shows only permitted items and drops empty groups', () => {
        const allowed = new Set(['masters.view']);
        const groups = visibleNavigation(NAVIGATION, (p) => allowed.has(p));
        const keys = groups.map((g) => g.key);
        expect(keys).toContain('home');
        expect(keys).toContain('masters');
        expect(keys).not.toContain('admin');
        expect(keys).not.toContain('oracle');
        expect(keys).not.toContain('production');
        expect(keys).not.toContain('inquiry');
    });

    it('shows the inquiry group (the Lot / PO search) with inquiry.view only', () => {
        const allowed = new Set(['inquiry.view']);
        const groups = visibleNavigation(NAVIGATION, (p) => allowed.has(p));
        expect(
            groups.find((g) => g.key === 'inquiry')?.items.map((i) => i.href),
        ).toEqual(['/search']);
        expect(groups.map((g) => g.key)).toEqual([
            'home',
            'production',
            'inquiry',
        ]);
        expect(
            visibleNavigation(NAVIGATION, () => false).map((g) => g.key),
        ).not.toContain('inquiry');
    });

    it('shows the Lots and POs screens with inquiry.view (and nothing else of production)', () => {
        const allowed = new Set(['inquiry.view']);
        const groups = visibleNavigation(NAVIGATION, (p) => allowed.has(p));
        expect(
            groups
                .find((g) => g.key === 'production')
                ?.items.map((i) => [i.key, i.href]),
        ).toEqual([
            ['lots', '/production/lots'],
            ['contracts', '/production/contracts'],
        ]);
    });

    it('shows the production group only with production.issue', () => {
        const allowed = new Set(['production.issue']);
        const groups = visibleNavigation(NAVIGATION, (p) => allowed.has(p));
        expect(
            groups
                .find((g) => g.key === 'production')
                ?.items.map((i) => i.href),
        ).toEqual(['/production/issue']);
        expect(groups.map((g) => g.key)).not.toContain('masters');
    });

    it('shows everything for a full permission set', () => {
        const groups = visibleNavigation(NAVIGATION, () => true);
        expect(
            groups.find((g) => g.key === 'admin')?.items.map((i) => i.key),
        ).toEqual(['users', 'roles', 'settings']);
    });
});
