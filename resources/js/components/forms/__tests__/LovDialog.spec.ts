import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import LovDialog from '@/components/forms/LovDialog.vue';

vi.mock('@/lib/lov', () => ({
    fetchLov: vi.fn().mockResolvedValue({
        columns: [{ key: 'code', label: 'Code' }, { key: 'label', label: 'Name' }],
        data: [
            { id: 1, code: 'SINGEING', label: 'Singeing' },
            { id: 2, code: 'DRYING', label: 'Drying' },
        ],
    }),
}));

describe('LovDialog', () => {
    it('navigates with arrows and selects with Enter', async () => {
        const wrapper = mount(LovDialog, {
            props: { name: 'departments', open: true },
            attachTo: document.body,
            global: { stubs: { teleport: true } },
        });
        await flushPromises();
        await new Promise((r) => setTimeout(r, 250));
        await flushPromises();

        const input = document.body.querySelector<HTMLInputElement>('input[data-lov-search]')!;
        input.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true }));
        input.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));
        await flushPromises();

        expect(wrapper.emitted('select')?.[0]?.[0]).toMatchObject({ id: 2, code: 'DRYING' });
        wrapper.unmount();
    });
});
