import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import ConfirmDialog from '@/components/forms/ConfirmDialog.vue';
import { useConfirm } from '@/composables/useConfirm';
import { useFormsKeys } from '@/composables/useFormsKeys';
import { setTranslations } from '@/lib/i18n';

afterEach(() => {
    document.body.innerHTML = '';
});

function button(text: string): HTMLButtonElement {
    return Array.from(
        document.body.querySelectorAll<HTMLButtonElement>(
            '[data-confirm-dialog] button',
        ),
    ).find((b) => b.textContent?.trim() === text)!;
}

describe('ConfirmDialog', () => {
    it('shows the translated question and emits confirm / cancel', async () => {
        setTranslations('en', 'ltr', {
            forms: {
                confirm: 'OK',
                cancel: 'Cancel',
                confirm_title: 'Please confirm',
                confirm_delete: 'Delete this record?',
            },
        });
        const wrapper = mount(ConfirmDialog, {
            props: { open: true, message: 'Delete this record?' },
            attachTo: document.body,
        });
        await flushPromises();
        const dialog = document.body.querySelector('[data-confirm-dialog]')!;
        expect(dialog.getAttribute('role')).toBe('alertdialog');
        expect(dialog.textContent).toContain('Please confirm');
        expect(dialog.textContent).toContain('Delete this record?');

        button('OK').click();
        await flushPromises();
        expect(wrapper.emitted('confirm')).toHaveLength(1);
        button('Cancel').click();
        await flushPromises();
        expect(wrapper.emitted('cancel')).toHaveLength(1);
        wrapper.unmount();
    });

    it('shadows the form shortcuts while open and lets them through once closed', async () => {
        const save = vi.fn();
        // the page's shortcuts live on its toolbar, a sibling rendered before the dialog (as on every screen)
        const Toolbar = defineComponent({
            setup() {
                useFormsKeys({ save });
                return () => h('div');
            },
        });
        const Page = defineComponent({
            props: { open: { type: Boolean, required: true } },
            setup(props) {
                return () => [
                    h(Toolbar),
                    h(ConfirmDialog, { open: props.open, message: 'Sure?' }),
                ];
            },
        });
        const wrapper = mount(Page, {
            props: { open: true },
            attachTo: document.body,
        });
        await flushPromises();
        const f10 = () =>
            window.dispatchEvent(
                new KeyboardEvent('keydown', { key: 'F10', cancelable: true }),
            );
        f10();
        expect(save).not.toHaveBeenCalled();
        await wrapper.setProps({ open: false });
        await flushPromises();
        f10();
        expect(save).toHaveBeenCalledTimes(1);
        wrapper.unmount();
    });

    it('takes a title and a confirm label of its own', async () => {
        mount(ConfirmDialog, {
            props: {
                open: true,
                message: 'Replace the route?',
                title: 'Apply template',
                confirmLabel: 'Apply',
                destructive: true,
            },
            attachTo: document.body,
        });
        await flushPromises();
        const dialog = document.body.querySelector('[data-confirm-dialog]')!;
        expect(dialog.textContent).toContain('Apply template');
        expect(button('Apply')).toBeDefined();
    });
});

describe('useConfirm', () => {
    it('resolves ask() with the choice and answers a superseded question with false', async () => {
        const confirm = useConfirm();
        expect(confirm.open.value).toBe(false);
        const first = confirm.ask('Delete?', { destructive: true });
        expect(confirm.open.value).toBe(true);
        expect(confirm.message.value).toBe('Delete?');
        expect(confirm.options.value.destructive).toBe(true);
        confirm.choose(true);
        await expect(first).resolves.toBe(true);
        expect(confirm.open.value).toBe(false);

        const second = confirm.ask('Again?');
        const third = confirm.ask('Third?');
        await expect(second).resolves.toBe(false);
        confirm.choose(false);
        await expect(third).resolves.toBe(false);
    });
});
