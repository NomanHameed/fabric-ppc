import { onBeforeUnmount, onMounted } from 'vue';
import { matchAction } from '@/forms/keymap';
import type { FormsAction } from '@/forms/keymap';

type Handlers = Partial<Record<FormsAction, () => void>>;

const stack: Handlers[] = [];
let listening = false;

function onKeydown(e: KeyboardEvent): void {
    const top = stack.at(-1);
    const action = matchAction(e);
    if (!top || !action) {
        return;
    }
    const handler = top[action];
    if (handler) {
        e.preventDefault();
        e.stopPropagation();
        handler();
    }
}

export function useFormsKeys(handlers: Handlers): void {
    onMounted(() => {
        stack.push(handlers);
        if (!listening) {
            window.addEventListener('keydown', onKeydown, { capture: true });
            listening = true;
        }
    });
    onBeforeUnmount(() => {
        const i = stack.lastIndexOf(handlers);
        if (i >= 0) {
            stack.splice(i, 1);
        }
    });
}
