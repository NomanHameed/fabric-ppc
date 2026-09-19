import { router } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * Oracle-Forms style "Save changes?" guard.
 * Render <ConfirmSaveDialog :open="dialogOpen" @choose="choose" /> in the page.
 */
export function useUnsavedGuard(isDirty: () => boolean, save: () => Promise<boolean>) {
    const dialogOpen = ref(false);
    let pendingVisit: (() => void) | null = null;
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
            pendingVisit = () => router.visit(visit.url, { method: 'get' });
            dialogOpen.value = true;
            return false;
        });
    });

    onBeforeUnmount(() => {
        window.removeEventListener('beforeunload', onBeforeUnload);
        removeRouterListener?.();
    });

    async function choose(choice: 'save' | 'discard' | 'cancel'): Promise<void> {
        dialogOpen.value = false;
        if (choice === 'cancel') {
            pendingVisit = null;
            return;
        }
        if (choice === 'save' && !(await save())) {
            return;
        }
        bypass = true;
        pendingVisit?.();
        pendingVisit = null;
        bypass = false;
    }

    return { dialogOpen, choose };
}
