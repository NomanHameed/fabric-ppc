export type FormsAction =
    | 'save' | 'newRecord' | 'deleteRecord' | 'enterQuery' | 'executeQuery'
    | 'previousRecord' | 'nextRecord' | 'clear' | 'print' | 'exit' | 'lov';

/** Single place to change shortcuts. Combos: Ctrl+, Shift+, Alt+ then KeyboardEvent.key. */
export const FORMS_KEYMAP: Record<FormsAction, string[]> = {
    save: ['F10', 'Ctrl+S'],
    newRecord: ['Ctrl+ArrowDown'],
    deleteRecord: ['Ctrl+Delete'],
    enterQuery: ['F7'],
    executeQuery: ['F8'],
    previousRecord: ['PageUp'],
    nextRecord: ['PageDown'],
    clear: [],
    print: ['Ctrl+P'],
    exit: ['Ctrl+Q'],
    lov: ['F9'],
};

function combo(e: KeyboardEvent): string {
    const k = e.key.length === 1 ? e.key.toUpperCase() : e.key;
    return [e.ctrlKey || e.metaKey ? 'Ctrl' : '', e.shiftKey && e.key.length > 1 ? 'Shift' : '', e.altKey ? 'Alt' : '', k]
        .filter(Boolean)
        .join('+');
}

export function matchAction(e: KeyboardEvent): FormsAction | null {
    const c = combo(e);
    for (const [action, keys] of Object.entries(FORMS_KEYMAP) as [FormsAction, string[]][]) {
        if (keys.includes(c)) {
            return action;
        }
    }
    return null;
}

export function keyLabel(action: FormsAction): string {
    return FORMS_KEYMAP[action].join(' / ');
}
