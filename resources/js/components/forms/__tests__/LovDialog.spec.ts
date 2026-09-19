import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import LovDialog from '@/components/forms/LovDialog.vue';
import { setTranslations } from '@/lib/i18n';
import { fetchLov } from '@/lib/lov';
import { statusLine } from '@/lib/statusLine';

vi.mock('@/lib/lov', () => ({
    fetchLov: vi.fn().mockResolvedValue({
        columns: [
            { key: 'code', label: 'Code' },
            { key: 'label', label: 'Name' },
        ],
        data: [
            { id: 1, code: 'SINGEING', label: 'Singeing' },
            { id: 2, code: 'DRYING', label: 'Drying' },
        ],
    }),
}));

afterEach(() => {
    // The dialog renders through a real `<teleport to="body">` (jsdom supports this
    // natively), so mounted content is attached directly to `document.body` and must
    // be cleaned up between tests.
    document.body.innerHTML = '';
});

describe('LovDialog', () => {
    it('navigates with arrows and selects with Enter', async () => {
        const wrapper = mount(LovDialog, {
            props: { name: 'departments', open: true },
            attachTo: document.body,
        });
        await flushPromises();
        await new Promise((r) => setTimeout(r, 250));
        await flushPromises();

        const input = document.body.querySelector<HTMLInputElement>(
            'input[data-lov-search]',
        )!;
        input.dispatchEvent(
            new KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true }),
        );
        input.dispatchEvent(
            new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }),
        );
        await flushPromises();

        expect(wrapper.emitted('select')?.[0]?.[0]).toMatchObject({
            id: 2,
            code: 'DRYING',
        });
        wrapper.unmount();
    });

    it('surfaces a status-line error when fetchLov fails', async () => {
        setTranslations('en', 'ltr', {
            forms: {
                lov_failed:
                    'Could not load the list. Check your connection and try again.',
            },
        });
        vi.mocked(fetchLov).mockRejectedValueOnce(new Error('network down'));

        const wrapper = mount(LovDialog, {
            props: { name: 'departments', open: true },
            attachTo: document.body,
        });
        await flushPromises();
        await new Promise((r) => setTimeout(r, 250));
        await flushPromises();

        expect(statusLine.state.message).toBe(
            'Could not load the list. Check your connection and try again.',
        );
        expect(statusLine.state.kind).toBe('error');
        wrapper.unmount();
    });
});
