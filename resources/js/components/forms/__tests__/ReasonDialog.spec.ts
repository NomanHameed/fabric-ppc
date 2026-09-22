import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it } from 'vitest';
import ReasonDialog from '@/components/forms/ReasonDialog.vue';
import { setTranslations } from '@/lib/i18n';

afterEach(() => {
    // The dialog renders through a real `<teleport to="body">`, so mounted content lands on document.body.
    document.body.innerHTML = '';
});

function buttons(): HTMLButtonElement[] {
    return Array.from(
        document.body.querySelectorAll<HTMLButtonElement>(
            '[role=dialog] button',
        ),
    );
}

describe('ReasonDialog', () => {
    it('keeps OK disabled while empty, emits the trimmed value on Enter, and cancels on close', async () => {
        setTranslations('en', 'ltr', {
            forms: { reason: 'Reason', confirm: 'OK', cancel: 'Cancel' },
        });
        const wrapper = mount(ReasonDialog, {
            props: {
                open: true,
                title: 'Quantity override',
                message: 'Only 100 m',
            },
            attachTo: document.body,
        });
        await flushPromises();

        expect(document.body.textContent).toContain('Quantity override');
        expect(document.body.textContent).toContain('Only 100 m');
        expect(document.body.textContent).toContain('Reason');
        const ok = buttons().find((b) => b.textContent?.trim() === 'OK')!;
        expect(ok.disabled).toBe(true);

        const input = document.body.querySelector<HTMLInputElement>(
            'input[data-reason-input]',
        )!;
        input.value = '  Oracle short  ';
        input.dispatchEvent(new Event('input', { bubbles: true }));
        await flushPromises();
        expect(ok.disabled).toBe(false);
        input.dispatchEvent(
            new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }),
        );
        await flushPromises();
        expect(wrapper.emitted('confirm')?.[0]).toEqual(['Oracle short']);

        buttons()
            .find((b) => b.textContent?.trim() === 'Cancel')!
            .click();
        await flushPromises();
        expect(wrapper.emitted('cancel')).toHaveLength(1);
        wrapper.unmount();
    });

    it('resets to the initial value each time it opens and accepts a number', async () => {
        const wrapper = mount(ReasonDialog, {
            props: {
                open: false,
                title: 'Total',
                type: 'number',
                initial: '250',
            },
            attachTo: document.body,
        });
        await wrapper.setProps({ open: true });
        await flushPromises();
        const input = document.body.querySelector<HTMLInputElement>(
            'input[data-reason-input]',
        )!;
        expect(input.type).toBe('number');
        expect(input.value).toBe('250');
        input.value = '300.5';
        input.dispatchEvent(new Event('input', { bubbles: true }));
        await flushPromises();
        buttons()
            .find(
                (b) =>
                    !b.textContent?.trim().includes('Cancel') &&
                    b.textContent?.trim() !== '',
            )!
            .click();
        expect(wrapper.emitted('confirm')?.[0]).toEqual(['300.5']);

        await wrapper.setProps({ open: false });
        await wrapper.setProps({ open: true });
        await flushPromises();
        expect(
            document.body.querySelector<HTMLInputElement>(
                'input[data-reason-input]',
            )!.value,
        ).toBe('250');
        wrapper.unmount();
    });

    it('picks the value from a list when options are given', async () => {
        setTranslations('en', 'ltr', {
            forms: {
                reason: 'Reason',
                confirm: 'OK',
                cancel: 'Cancel',
                select: 'Select',
            },
        });
        const wrapper = mount(ReasonDialog, {
            props: {
                open: true,
                title: 'Put lot on hold',
                options: [
                    { value: 'PARTY_HOLD', label: 'Party hold' },
                    { value: 'OTHER', label: 'Other' },
                ],
            },
            attachTo: document.body,
        });
        await flushPromises();
        const select = document.body.querySelector<HTMLSelectElement>(
            'select[data-reason-input]',
        )!;
        expect(select).not.toBeNull();
        expect(
            document.body.querySelector('input[data-reason-input]'),
        ).toBeNull();
        expect(
            Array.from(select.options).map((o) => o.textContent?.trim()),
        ).toEqual(['Select', 'Party hold', 'Other']);
        const ok = buttons().find((b) => b.textContent?.trim() === 'OK')!;
        expect(ok.disabled).toBe(true);

        select.value = 'PARTY_HOLD';
        select.dispatchEvent(new Event('change', { bubbles: true }));
        await flushPromises();
        expect(ok.disabled).toBe(false);
        select.dispatchEvent(
            new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }),
        );
        await flushPromises();
        expect(wrapper.emitted('confirm')?.[0]).toEqual(['PARTY_HOLD']);
        wrapper.unmount();
    });
});
