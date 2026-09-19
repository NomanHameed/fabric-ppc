import type { PendingVisit, VisitOptions } from '@inertiajs/core';
import { router } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * Picks the subset of a blocked visit's options that is safe to replay after
 * Save/Discard, so a guarded GET navigation reproduces the original request
 * (scroll/state preservation, partial reloads, headers, …) instead of a plain
 * full reload. Deliberately excludes the `PendingVisit`-only bookkeeping
 * fields (`id`, `url`, `completed`, `cancelled`, `interrupted`).
 */
export function replayOptions(visit: PendingVisit): VisitOptions {
    return {
        method: 'get',
        preserveScroll: visit.preserveScroll,
        preserveState: visit.preserveState,
        only: visit.only,
        except: visit.except,
        headers: visit.headers,
        replace: visit.replace,
        errorBag: visit.errorBag,
        reset: visit.reset,
        queryStringArrayFormat: visit.queryStringArrayFormat,
        forceFormData: visit.forceFormData,
    };
}

/**
 * Oracle-Forms style "Save changes?" guard.
 * Render <ConfirmSaveDialog :open="dialogOpen" @choose="choose" /> in the page.
 */
export function useUnsavedGuard(
    isDirty: () => boolean,
    save: () => Promise<boolean>,
) {
    const dialogOpen = ref(false);
    let pendingAction: (() => void) | null = null;
    let bypass = false;

    const onBeforeUnload = (e: BeforeUnloadEvent) => {
        if (isDirty()) {
            e.preventDefault();
        }
    };

    let removeRouterListener: (() => void) | undefined;

    onMounted(() => {
        window.addEventListener('beforeunload', onBeforeUnload);
        removeRouterListener = router.on('before', (event) => {
            const visit = event.detail.visit;
            if (bypass || !isDirty() || visit.method !== 'get') {
                return;
            }
            pendingAction = () => router.visit(visit.url, replayOptions(visit));
            dialogOpen.value = true;
            return false;
        });
    });

    onBeforeUnmount(() => {
        window.removeEventListener('beforeunload', onBeforeUnload);
        removeRouterListener?.();
    });

    async function choose(
        choice: 'save' | 'discard' | 'cancel',
    ): Promise<void> {
        dialogOpen.value = false;
        if (choice === 'cancel') {
            pendingAction = null;
            return;
        }
        if (choice === 'save' && !(await save())) {
            return;
        }
        bypass = true;
        pendingAction?.();
        pendingAction = null;
        bypass = false;
    }

    /**
     * Runs `fn` immediately when the form is clean. Otherwise defers it behind the
     * same "Save changes?" dialog used for guarded router visits: `fn` runs after a
     * successful Save or after Discard, and never runs on Cancel.
     */
    function guard(fn: () => void): void {
        if (!isDirty()) {
            fn();
            return;
        }
        pendingAction = fn;
        dialogOpen.value = true;
    }

    return { dialogOpen, choose, guard };
}
