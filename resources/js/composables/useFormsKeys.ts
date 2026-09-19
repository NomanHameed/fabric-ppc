import { onBeforeUnmount, onMounted } from 'vue';
import { matchAction } from '@/forms/keymap';
import type { FormsAction } from '@/forms/keymap';

type Handlers = Partial<Record<FormsAction, () => void>>;
type Entry = { handlers: Handlers; active: () => boolean };

const stack: Entry[] = [];
let listening = false;

function onKeydown(e: KeyboardEvent): void {
    const action = matchAction(e);
    if (!action) {
        return;
    }
    for (let i = stack.length - 1; i >= 0; i -= 1) {
        const entry = stack[i];
        if (!entry.active()) {
            continue;
        }
        const handler = entry.handlers[action];
        if (handler) {
            e.preventDefault();
            e.stopPropagation();
            handler();
        }
        return;
    }
}

/**
 * Registers a set of Forms keyboard shortcut handlers. Handlers are stacked: the
 * top-most *active* entry (see `active`, default always-active) wins for a given
 * keypress, whether or not it has a handler for that particular action — so an
 * always-mounted-but-conditionally-active entry (e.g. a dialog that toggles
 * `open`) only shadows the entries below it while it is actually active.
 */
export function useFormsKeys(handlers: Handlers, active: () => boolean = () => true): void {
    const entry: Entry = { handlers, active };
    onMounted(() => {
        stack.push(entry);
        if (!listening) {
            window.addEventListener('keydown', onKeydown, { capture: true });
            listening = true;
        }
    });
    onBeforeUnmount(() => {
        const i = stack.indexOf(entry);
        if (i >= 0) {
            stack.splice(i, 1);
        }
    });
}
