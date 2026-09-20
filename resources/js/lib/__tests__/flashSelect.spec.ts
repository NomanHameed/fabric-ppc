import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { defineComponent, h } from 'vue';
import { useFlashSelect } from '@/lib/flashSelect';

function flash(flash: Record<string, unknown>): void {
    document.dispatchEvent(
        new CustomEvent('inertia:flash', { detail: { flash } }),
    );
}

function mountWithComposable() {
    let api!: ReturnType<typeof useFlashSelect>;
    const wrapper = mount(
        defineComponent({
            setup() {
                api = useFlashSelect();
                return () => h('div');
            },
        }),
    );
    return { wrapper, api };
}

describe('useFlashSelect', () => {
    it('captures a flashed selectedId and hands it out once', () => {
        const { wrapper, api } = mountWithComposable();

        flash({ selectedId: 7, toast: { type: 'success', message: 'Saved' } });

        expect(api.pending.value).toBe(7);
        expect(api.take()).toBe(7);
        expect(api.take()).toBeNull();
        wrapper.unmount();
    });

    it('keeps the id until the rows contain it', () => {
        const { wrapper, api } = mountWithComposable();
        flash({ selectedId: 7 });

        expect(api.take((id) => [1, 2].includes(id))).toBeNull();
        expect(api.pending.value).toBe(7);
        expect(api.take((id) => [1, 7].includes(id))).toBe(7);
        expect(api.pending.value).toBeNull();
        wrapper.unmount();
    });

    it('ignores flashes without a numeric selectedId and stops listening after unmount', () => {
        const { wrapper, api } = mountWithComposable();

        flash({ toast: { type: 'success', message: 'Saved' } });
        expect(api.take()).toBeNull();

        wrapper.unmount();
        flash({ selectedId: 9 });
        expect(api.pending.value).toBeNull();
    });
});
