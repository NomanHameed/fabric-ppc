import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { setTranslations } from '@/lib/i18n';

const get = vi.fn();
vi.mock('@inertiajs/vue3', () => ({
    router: { get: (...args: unknown[]) => get(...args) },
}));

async function mountSearch() {
    const { default: GlobalSearch } =
        await import('@/components/GlobalSearch.vue');
    const wrapper = mount(GlobalSearch, { attachTo: document.body });
    await flushPromises();
    const input = wrapper.find<HTMLInputElement>('input[data-global-search]');
    return { wrapper, input };
}

beforeEach(() => {
    get.mockClear();
    setTranslations('en', 'ltr', {
        inquiry: {
            search: {
                placeholder: 'Lot / PO …',
                label: 'Lot / PO search',
                shortcut: 'Ctrl+K',
            },
        },
    });
});

afterEach(() => {
    document.body.innerHTML = '';
});

describe('GlobalSearch', () => {
    it('sends the trimmed query to /search on Enter', async () => {
        const { wrapper, input } = await mountSearch();
        expect(input.attributes('placeholder')).toBe('Lot / PO …');
        await input.setValue('  264899 ');
        await wrapper.find('form').trigger('submit'); // what Enter in the box does
        expect(get).toHaveBeenCalledTimes(1);
        expect(get).toHaveBeenCalledWith('/search', { q: '264899' });
        wrapper.unmount();
    });

    it('does nothing on Enter with an empty box', async () => {
        const { wrapper, input } = await mountSearch();
        await input.setValue('   ');
        await wrapper.find('form').trigger('submit');
        expect(get).not.toHaveBeenCalled();
        wrapper.unmount();
    });

    it('focuses the box on Ctrl+K, but not while a dialog is open, and leaves Forms keys alone', async () => {
        const { wrapper, input } = await mountSearch();
        const focus = vi.spyOn(input.element, 'focus');
        const ctrlK = () => {
            const ev = new KeyboardEvent('keydown', {
                key: 'k',
                ctrlKey: true,
                cancelable: true,
                bubbles: true,
            });
            window.dispatchEvent(ev);
            return ev;
        };
        expect(ctrlK().defaultPrevented).toBe(true);
        expect(focus).toHaveBeenCalledTimes(1);

        // Ctrl+S is Save in the Forms key map: the search box must not take it
        const ctrlS = new KeyboardEvent('keydown', {
            key: 's',
            ctrlKey: true,
            cancelable: true,
            bubbles: true,
        });
        window.dispatchEvent(ctrlS);
        expect(ctrlS.defaultPrevented).toBe(false);
        expect(focus).toHaveBeenCalledTimes(1);

        const dialog = document.createElement('div');
        dialog.setAttribute('role', 'dialog');
        document.body.appendChild(dialog);
        expect(ctrlK().defaultPrevented).toBe(false);
        expect(focus).toHaveBeenCalledTimes(1);
        wrapper.unmount();
    });
});
