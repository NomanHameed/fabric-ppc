import { onBeforeUnmount, onMounted } from 'vue';
import { matchAction } from '@/forms/keymap';
import type { FormsAction } from '@/forms/keymap';

type Handlers = Partial<Record<FormsAction, () => void>>;
type Entry = { handlers: Handlers; active: () => boolean };

const stack: Entry[] = [];
let listening = false;

/** Record navigation/editing keys an AG Grid handles itself (Ctrl+Delete, Ctrl+Down, Page Up/Down). */
const GRID_OWNED: ReadonlySet<FormsAction> = new Set<FormsAction>(['newRecord', 'deleteRecord', 'previousRecord', 'nextRecord']);

function onKeydown(e: KeyboardEvent): void {
    const action = matchAction(e);
    if (!action) {
        return;
    }
    // Inside a grid these keys belong to the grid's rows, not the form's record (e.g. Ctrl+Delete in a
    // detail grid must not delete the master record). Save, query and exit keys still apply.
    if (GRID_OWNED.has(action) && (e.target as Element | null)?.closest?.('.ag-root-wrapper')) {
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
