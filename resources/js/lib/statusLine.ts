import { reactive } from 'vue';

type Kind = 'info' | 'success' | 'error';

const initial = () => ({
    message: '',
    kind: 'info' as Kind,
    recordIndex: 0,
    recordCount: 0,
    hint: '',
    dirty: false,
});

const state = reactive(initial());

export const statusLine = {
    state,
    setMessage(message: string, kind: Kind = 'info') {
        state.message = message;
        state.kind = kind;
    },
    setRecord(index: number, count: number) {
        state.recordIndex = index;
        state.recordCount = count;
    },
    setHint(hint: string) {
        state.hint = hint;
    },
    setDirty(dirty: boolean) {
        state.dirty = dirty;
    },
    reset() {
        Object.assign(state, initial());
    },
};
