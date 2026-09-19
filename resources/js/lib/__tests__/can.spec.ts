import { describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { auth: { permissions: ['inquiry.view'], roles: ['viewer'] } } }),
}));

describe('can', () => {
    it('checks shared permissions', async () => {
        const { can } = await import('@/lib/can');
        expect(can('inquiry.view')).toBe(true);
        expect(can('oracle.import.run')).toBe(false);
    });
});
