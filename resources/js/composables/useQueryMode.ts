import { reactive, ref } from 'vue';

export function useQueryMode<T extends Record<string, string | null>>(fields: (keyof T)[]) {
    const mode = ref<'normal' | 'enterQuery'>('normal');
    const criteria = reactive(Object.fromEntries(fields.map((f) => [f, null]))) as T;

    function enterQuery(): void {
        for (const f of fields) {
            (criteria as Record<keyof T, string | null>)[f] = null;
        }
        mode.value = 'enterQuery';
    }

    function cancelQuery(): void {
        mode.value = 'normal';
    }

    function takeCriteria(): Partial<T> {
        const out: Partial<T> = {};
        for (const f of fields) {
            const v = criteria[f];
            if (typeof v === 'string' && v.trim() !== '') {
                out[f] = v.trim() as T[keyof T];
            }
        }
        mode.value = 'normal';
        return out;
    }

    return { mode, criteria, enterQuery, cancelQuery, takeCriteria };
}
