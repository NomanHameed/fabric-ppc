import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it } from 'vitest';
import { defineComponent, h } from 'vue';
import FormBlock from '@/components/forms/FormBlock.vue';

afterEach(() => {
    document.body.innerHTML = '';
});

function press(el: Element, init: KeyboardEventInit): boolean {
    return el.dispatchEvent(
        new KeyboardEvent('keydown', {
            key: 'Enter',
            bubbles: true,
            cancelable: true,
            ...init,
        }),
    );
}

function mountBlock() {
    return mount(
        defineComponent({
            render: () =>
                h(FormBlock, null, {
                    default: () => [
                        h('input', { id: 'a' }),
                        h('input', { id: 'b' }),
                        h('input', { id: 'hidden', type: 'hidden' }),
                        h('input', { id: 'c', disabled: true }),
                        h('textarea', { id: 'notes' }),
                        h('button', { id: 'go', type: 'button' }, 'Go'),
                    ],
                }),
        }),
        { attachTo: document.body },
    );
}

describe('FormBlock', () => {
    it('moves focus to the next enabled field on Enter, like Tab', () => {
        const wrapper = mountBlock();
        const a = document.getElementById('a')!;
        const b = document.getElementById('b')!;
        a.focus();

        const notPrevented = press(a, {});

        expect(notPrevented).toBe(false);
        expect(document.activeElement).toBe(b);

        // Skips hidden and disabled inputs.
        expect(press(b, {})).toBe(false);
        expect(document.activeElement?.id).toBe('notes');
        wrapper.unmount();
    });

    it('leaves Enter alone in textareas, on buttons, and with modifier keys', () => {
        const wrapper = mountBlock();
        const notes = document.getElementById('notes')!;
        notes.focus();
        expect(press(notes, {})).toBe(true);
        expect(document.activeElement).toBe(notes);

        const a = document.getElementById('a')!;
        a.focus();
        expect(press(a, { shiftKey: true })).toBe(true);
        expect(document.activeElement).toBe(a);

        const go = document.getElementById('go')!;
        go.focus();
        expect(press(go, {})).toBe(true);
        expect(document.activeElement).toBe(go);
        wrapper.unmount();
    });

    it('does nothing on the last field', () => {
        const wrapper = mount(
            defineComponent({
                render: () =>
                    h(FormBlock, null, {
                        default: () => [
                            h('input', { id: 'x' }),
                            h('input', { id: 'y' }),
                        ],
                    }),
            }),
            { attachTo: document.body },
        );
        const y = document.getElementById('y')!;
        y.focus();
        expect(press(y, {})).toBe(true);
        expect(document.activeElement).toBe(y);
        wrapper.unmount();
    });
});
