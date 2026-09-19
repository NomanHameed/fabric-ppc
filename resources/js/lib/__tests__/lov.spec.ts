import { afterEach, describe, expect, it, vi } from 'vitest';
import { fetchLov } from '@/lib/lov';

describe('fetchLov', () => {
    afterEach(() => vi.restoreAllMocks());

    it('builds the query string with filters', async () => {
        const spy = vi
            .spyOn(globalThis, 'fetch')
            .mockResolvedValue(
                new Response(JSON.stringify({ columns: [], data: [] })),
            );
        await fetchLov('operations', {
            q: 'dye',
            filters: { department_id: 3, kind: null },
        });
        const url = spy.mock.calls[0][0] as string;
        expect(url).toContain('/lov/operations?');
        expect(url).toContain('q=dye');
        expect(url).toContain('filters%5Bdepartment_id%5D=3');
        expect(url).not.toContain('kind');
    });
});
