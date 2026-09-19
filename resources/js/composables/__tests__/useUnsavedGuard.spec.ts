import type { PendingVisit } from '@inertiajs/core';
import { describe, expect, it } from 'vitest';
import { replayOptions } from '@/composables/useUnsavedGuard';

function makeVisit(overrides: Partial<PendingVisit> = {}): PendingVisit {
    return {
        id: 'visit-1',
        url: new URL('https://example.test/contracts?page=2'),
        completed: false,
        cancelled: false,
        interrupted: false,
        method: 'get',
        data: {},
        replace: false,
        preserveScroll: true,
        preserveState: true,
        only: ['contracts'],
        except: [],
        headers: { 'X-Test': '1' },
        errorBag: 'default',
        forceFormData: false,
        queryStringArrayFormat: 'brackets',
        async: false,
        showProgress: true,
        prefetch: false,
        fresh: false,
        reset: ['filters'],
        preserveUrl: false,
        preserveErrors: false,
        invalidateCacheTags: [],
        viewTransition: false,
        component: null,
        pageProps: null,
        cached: false,
        ...overrides,
    };
}

describe('replayOptions', () => {
    it('keeps the safe VisitOptions subset needed to reproduce the original navigation', () => {
        const visit = makeVisit();

        expect(replayOptions(visit)).toEqual({
            method: 'get',
            preserveScroll: true,
            preserveState: true,
            only: ['contracts'],
            except: [],
            headers: { 'X-Test': '1' },
            replace: false,
            errorBag: 'default',
            reset: ['filters'],
            queryStringArrayFormat: 'brackets',
            forceFormData: false,
        });
    });

    it('never forwards PendingVisit-only bookkeeping fields', () => {
        const options = replayOptions(makeVisit());

        expect(options).not.toHaveProperty('id');
        expect(options).not.toHaveProperty('url');
        expect(options).not.toHaveProperty('completed');
        expect(options).not.toHaveProperty('cancelled');
        expect(options).not.toHaveProperty('interrupted');
    });

    it('reflects a differently configured visit (partial reload, no scroll preservation)', () => {
        const visit = makeVisit({ preserveScroll: false, only: ['stats'], except: ['heavyBlock'] });

        const options = replayOptions(visit);

        expect(options.preserveScroll).toBe(false);
        expect(options.only).toEqual(['stats']);
        expect(options.except).toEqual(['heavyBlock']);
    });
});
