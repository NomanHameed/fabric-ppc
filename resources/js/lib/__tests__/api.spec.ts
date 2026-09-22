import { afterEach, describe, expect, it, vi } from 'vitest';
import { ApiError, getJson, postJson, xsrfToken } from '@/lib/api';

describe('api', () => {
    afterEach(() => {
        vi.restoreAllMocks();
        document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT';
    });

    it('reads the XSRF cookie and sends it with a JSON POST', async () => {
        document.cookie = `XSRF-TOKEN=${encodeURIComponent('abc=123')}`;
        expect(xsrfToken()).toBe('abc=123');
        const spy = vi
            .spyOn(globalThis, 'fetch')
            .mockResolvedValue(
                new Response(JSON.stringify({ allocations: { 0: 1 } })),
            );
        const out = await postJson<{ allocations: Record<string, number> }>(
            '/production/sheets/split-batch',
            { total: 1 },
        );
        expect(out.allocations[0]).toBe(1);
        const [url, init] = spy.mock.calls[0] as [string, RequestInit];
        expect(url).toBe('/production/sheets/split-batch');
        expect(init.method).toBe('POST');
        expect(init.body).toBe('{"total":1}');
        const headers = init.headers as Record<string, string>;
        expect(headers['X-XSRF-TOKEN']).toBe('abc=123');
        expect(headers['Content-Type']).toBe('application/json');
        expect(headers.Accept).toBe('application/json');
    });

    it('throws an ApiError carrying the validation errors', async () => {
        vi.spyOn(globalThis, 'fetch').mockResolvedValue(
            new Response(
                JSON.stringify({
                    message: 'Invalid.',
                    errors: { total: ['The total must be greater than 0.'] },
                }),
                { status: 422 },
            ),
        );
        await expect(getJson('/x')).rejects.toMatchObject({
            status: 422,
            errors: { total: ['The total must be greater than 0.'] },
            message: 'Invalid.',
        });
        vi.spyOn(globalThis, 'fetch').mockResolvedValue(
            new Response('nope', { status: 500 }),
        );
        const err = await getJson('/x').catch((e: unknown) => e);
        expect(err).toBeInstanceOf(ApiError);
        expect((err as ApiError).status).toBe(500);
    });

    it('reports an expired session (419) as an ApiError with its message and no field errors', async () => {
        vi.spyOn(globalThis, 'fetch').mockResolvedValue(
            new Response(JSON.stringify({ message: 'CSRF token mismatch.' }), {
                status: 419,
            }),
        );
        const err = await postJson('/x', {}).catch((e: unknown) => e);
        expect(err).toBeInstanceOf(ApiError);
        expect(err).toMatchObject({
            status: 419,
            errors: {},
            message: 'CSRF token mismatch.',
        });
    });
});
