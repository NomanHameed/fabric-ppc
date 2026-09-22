import { ref } from 'vue';

export type ReasonPromptOptions = {
    title: string;
    message?: string;
    label?: string;
    initial?: string;
    type?: 'text' | 'number';
    placeholder?: string;
    /** Pick the value from a list (a lookup code) instead of typing it. */
    options?: { value: string; label: string }[];
};

/**
 * Promise-shaped driver for one ReasonDialog per page: `const reason = await prompt.ask({ title })` resolves
 * with the trimmed value, or null when the user cancels. A second `ask` while one is open cancels the first.
 * Render: <ReasonDialog :open="prompt.open.value" v-bind="prompt.options.value" @confirm="prompt.confirm" @cancel="prompt.cancel" />
 */
export function useReasonPrompt() {
    const open = ref(false);
    const options = ref<ReasonPromptOptions>({ title: '' });
    let resolver: ((value: string | null) => void) | null = null;

    function ask(opts: ReasonPromptOptions): Promise<string | null> {
        resolver?.(null);
        options.value = opts;
        open.value = true;
        return new Promise<string | null>((resolve) => {
            resolver = resolve;
        });
    }

    function settle(value: string | null): void {
        const resolve = resolver;
        resolver = null;
        open.value = false;
        resolve?.(value);
    }

    return {
        open,
        options,
        ask,
        confirm: (value: string) => settle(value),
        cancel: () => settle(null),
    };
}
