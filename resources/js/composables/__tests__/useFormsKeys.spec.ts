import { mount } from '@vue/test-utils';
import { defineComponent, ref } from 'vue';
import { describe, expect, it, vi } from 'vitest';
import { useFormsKeys } from '@/composables/useFormsKeys';

function pressF10(): void {
    window.dispatchEvent(new KeyboardEvent('keydown', { key: 'F10', bubbles: true, cancelable: true }));
}

describe('useFormsKeys', () => {
    it('lets an inactive overlay entry fall through to the entry below it', () => {
        const save = vi.fn();
        const Base = defineComponent({
            setup() {
                useFormsKeys({ save });
                return () => null;
            },
        });

        const flag = ref(false);
        const Overlay = defineComponent({
            setup() {
                useFormsKeys({}, () => flag.value);
                return () => null;
            },
        });

        const base = mount(Base);
        const overlay = mount(Overlay);

        flag.value = false;
        pressF10();
        expect(save).toHaveBeenCalledTimes(1);

        flag.value = true;
        pressF10();
        expect(save).toHaveBeenCalledTimes(1);

        overlay.unmount();
        base.unmount();
    });
});
