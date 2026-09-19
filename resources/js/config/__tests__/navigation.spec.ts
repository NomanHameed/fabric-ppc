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
    });

    it('shows everything for a full permission set', () => {
        const groups = visibleNavigation(NAVIGATION, () => true);
        expect(
            groups.find((g) => g.key === 'admin')?.items.map((i) => i.key),
        ).toEqual(['users', 'roles', 'settings']);
    });
});
