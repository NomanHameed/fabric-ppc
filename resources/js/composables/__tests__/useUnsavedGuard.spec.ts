import type { PendingVisit } from '@inertiajs/core';
import { mount } from '@vue/test-utils';
import { defineComponent } from 'vue';
import { describe, expect, it, vi } from 'vitest';
import { replayOptions, useUnsavedGuard } from '@/composables/useUnsavedGuard';

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
        const visit = makeVisit({
            preserveScroll: false,
            only: ['stats'],
            except: ['heavyBlock'],
        });

        const options = replayOptions(visit);

        expect(options.preserveScroll).toBe(false);
        expect(options.only).toEqual(['stats']);
        expect(options.except).toEqual(['heavyBlock']);
    });
});

function mountGuard(isDirty: () => boolean, save: () => Promise<boolean>) {
    let api!: ReturnType<typeof useUnsavedGuard>;
    const wrapper = mount(
        defineComponent({
            setup() {
                api = useUnsavedGuard(isDirty, save);
                return () => null;
            },
        }),
    );
    return { wrapper, guard: api };
}

describe('useUnsavedGuard: guard()', () => {
    it('runs the action immediately when the form is clean', () => {
        const fn = vi.fn();
        const { guard } = mountGuard(
            () => false,
            async () => true,
        );

        guard.guard(fn);

        expect(fn).toHaveBeenCalledTimes(1);
        expect(guard.dialogOpen.value).toBe(false);
    });

    it('opens the dialog when dirty, and runs the action only after choosing discard', async () => {
        const fn = vi.fn();
        const { guard } = mountGuard(
            () => true,
            async () => true,
        );

        guard.guard(fn);
        expect(fn).not.toHaveBeenCalled();
        expect(guard.dialogOpen.value).toBe(true);

        await guard.choose('discard');
        expect(fn).toHaveBeenCalledTimes(1);
        expect(guard.dialogOpen.value).toBe(false);
    });

    it('runs the action after choosing save when save resolves true', async () => {
        const fn = vi.fn();
        const { guard } = mountGuard(
            () => true,
            async () => true,
        );

        guard.guard(fn);
        await guard.choose('save');

        expect(fn).toHaveBeenCalledTimes(1);
    });

    it('does not run the action after choosing save when save resolves false', async () => {
        const fn = vi.fn();
        const { guard } = mountGuard(
            () => true,
            async () => false,
        );

        guard.guard(fn);
        await guard.choose('save');

        expect(fn).not.toHaveBeenCalled();
    });

    it('never runs the action when choosing cancel', async () => {
        const fn = vi.fn();
        const { guard } = mountGuard(
            () => true,
            async () => true,
        );

        guard.guard(fn);
        await guard.choose('cancel');

        expect(fn).not.toHaveBeenCalled();
        expect(guard.dialogOpen.value).toBe(false);
    });
});
