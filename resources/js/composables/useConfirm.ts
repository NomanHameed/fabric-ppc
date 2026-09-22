import { ref } from 'vue';

export type ConfirmOptions = {
    title?: string;
    confirmLabel?: string;
    destructive?: boolean;
};

/**
 * Promise-shaped `window.confirm` for the ConfirmDialog: `if (await confirm.ask(t('forms.confirm_delete'))) …`.
 * One question at a time; a second `ask` while one is open answers the first with false.
 */
export function useConfirm() {
    const open = ref(false);
    const message = ref('');
    const options = ref<ConfirmOptions>({});
    let resolver: ((ok: boolean) => void) | null = null;

    function ask(text: string, opts: ConfirmOptions = {}): Promise<boolean> {
        resolver?.(false);
        message.value = text;
        options.value = opts;
        open.value = true;
        return new Promise<boolean>((resolve) => {
            resolver = resolve;
        });
    }

    function choose(ok: boolean): void {
        const resolve = resolver;
        resolver = null;
        open.value = false;
        resolve?.(ok);
    }

    return { open, message, options, ask, choose };
}
