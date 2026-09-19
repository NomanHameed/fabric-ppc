import { mount } from '@vue/test-utils';
import { defineComponent, ref } from 'vue';
import { describe, expect, it, vi } from 'vitest';
import { useFormsKeys } from '@/composables/useFormsKeys';

function pressF10(): void {
    window.dispatchEvent(
        new KeyboardEvent('keydown', {
            key: 'F10',
            bubbles: true,
            cancelable: true,
        }),
    );
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

    it('leaves record keys to an AG Grid but still handles them outside it', () => {
        const deleteRecord = vi.fn();
        const save = vi.fn();
        const Form = defineComponent({
            setup() {
                useFormsKeys({ deleteRecord, save });
                return () => null;
            },
        });
        const form = mount(Form);

        const grid = document.createElement('div');
        grid.className = 'ag-root-wrapper';
        const cell = document.createElement('div');
        cell.tabIndex = 0;
        grid.appendChild(cell);
        const field = document.createElement('input');
        document.body.append(grid, field);

        const inGrid = new KeyboardEvent('keydown', {
            key: 'Delete',
            ctrlKey: true,
            bubbles: true,
            cancelable: true,
        });
        cell.dispatchEvent(inGrid);
        expect(deleteRecord).not.toHaveBeenCalled();
        expect(inGrid.defaultPrevented).toBe(false);

        const saveInGrid = new KeyboardEvent('keydown', {
            key: 'F10',
            bubbles: true,
            cancelable: true,
        });
        cell.dispatchEvent(saveInGrid);
        expect(save).toHaveBeenCalledTimes(1);
        expect(saveInGrid.defaultPrevented).toBe(true);

        const outside = new KeyboardEvent('keydown', {
            key: 'Delete',
            ctrlKey: true,
            bubbles: true,
            cancelable: true,
        });
        field.dispatchEvent(outside);
        expect(deleteRecord).toHaveBeenCalledTimes(1);
        expect(outside.defaultPrevented).toBe(true);

        grid.remove();
        field.remove();
        form.unmount();
    });
});
