import { router } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * Captures `Inertia::flash('selectedId', id)` sent by a store action so the page can select the
 * new record once the refreshed rows arrive. The flash arrives through Inertia's `flash` router
 * event (the same channel `flashToast.ts` listens to), which fires *after* the page props have
 * been swapped in — so a page should watch both its rows and `pending`, and `take()` only once
 * the wanted id is actually among the rows (see the master pages for the pattern).
 */
export function useFlashSelect() {
    const pending = ref<number | null>(null);
    let off: (() => void) | undefined;
    onMounted(() => {
        off = router.on('flash', (event) => {
            const id = (event as CustomEvent).detail?.flash?.selectedId;
            if (typeof id === 'number') {
                pending.value = id;
            }
        });
    });
    onBeforeUnmount(() => off?.());

    /**
     * Returns and clears the pending id when `has(id)` accepts it (it is among the loaded rows);
     * otherwise keeps it for the next call and returns null.
     */
    function take(has: (id: number) => boolean = () => true): number | null {
        const id = pending.value;
        if (id === null || !has(id)) {
            return null;
        }
        pending.value = null;
        return id;
    }

    return { pending, take };
}
