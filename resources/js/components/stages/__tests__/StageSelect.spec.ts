import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import StageSelect from '@/components/stages/StageSelect.vue';
import type { Stage } from '@/types/stages';

/*
 * The stage select reads its options from the API — never from a hard-coded list — which is the one property
 * spec §17 insists on: a stage added on the Stages master must appear here without a deploy.
 *
 * `t()` has no dictionary installed in a unit test, so it returns the key; asserting on keys is deliberate
 * (it proves the string goes through i18n rather than being written into the template).
 */

function stage(over: Partial<Stage> = {}): Stage {
    return {
        id: 1,
        code: 'DYEING',
        name: 'Dyeing',
        description: null,
        sort_order: 20,
        is_active: true,
        processes_count: 1,
        ...over,
    };
}

const getJson = vi.fn();
vi.mock('@/lib/api', () => ({
    getJson: (...args: unknown[]) => getJson(...args),
    isAbortError: (e: unknown) =>
        (e as { name?: string })?.name === 'AbortError',
}));

describe('StageSelect', () => {
    afterEach(() => {
        getJson.mockReset();
    });

    it('loads its options from /api/stages', async () => {
        getJson.mockResolvedValue({
            data: [
                stage(),
                stage({ id: 2, code: 'PRINTING', name: 'Printing' }),
            ],
        });
        const wrapper = mount(StageSelect, { props: { modelValue: null } });
        await flushPromises();

        expect(getJson.mock.calls[0][0]).toBe('/api/stages');
        const options = wrapper.findAll('option');
        // the placeholder, then one option per stage, in the order the API gave them
        expect(options.map((o) => o.text())).toEqual([
            'production.lots.stage_pick',
            'Dyeing',
            'Printing',
        ]);
        expect(wrapper.emitted('loaded')).toHaveLength(1);
        wrapper.unmount();
    });

    it('emits the chosen id as a number, and null for the placeholder', async () => {
        getJson.mockResolvedValue({ data: [stage({ id: 7 })] });
        const wrapper = mount(StageSelect, { props: { modelValue: null } });
        await flushPromises();

        await wrapper.find('select').setValue('7');
        await wrapper.find('select').setValue('');

        expect(wrapper.emitted('update:modelValue')).toEqual([[7], [null]]);
        wrapper.unmount();
    });

    it("shows the record's own stage even when it is no longer active", async () => {
        getJson.mockResolvedValue({ data: [stage({ id: 1 })] });
        const wrapper = mount(StageSelect, {
            props: {
                modelValue: 99,
                fallback: { id: 99, name: 'Retired stage' },
            },
        });
        await flushPromises();

        expect(wrapper.findAll('option').map((o) => o.text())).toContain(
            'Retired stage',
        );
        expect(
            (wrapper.find('select').element as HTMLSelectElement).value,
        ).toBe('99');
        wrapper.unmount();
    });

    it('does not add the fallback when the active list already has it', async () => {
        getJson.mockResolvedValue({ data: [stage({ id: 1, name: 'Dyeing' })] });
        const wrapper = mount(StageSelect, {
            props: { modelValue: 1, fallback: { id: 1, name: 'Dyeing' } },
        });
        await flushPromises();

        expect(
            wrapper.findAll('option').filter((o) => o.text() === 'Dyeing'),
        ).toHaveLength(1);
        wrapper.unmount();
    });

    it('is disabled while loading and when the form says so', async () => {
        let resolve: (v: unknown) => void = () => {};
        getJson.mockReturnValue(new Promise((r) => (resolve = r)));
        const wrapper = mount(StageSelect, { props: { modelValue: null } });
        await wrapper.vm.$nextTick();

        expect(wrapper.find('select').attributes('disabled')).toBeDefined();
        resolve({ data: [stage()] });
        await flushPromises();
        expect(wrapper.find('select').attributes('disabled')).toBeUndefined();

        await wrapper.setProps({ disabled: true });
        expect(wrapper.find('select').attributes('disabled')).toBeDefined();
        wrapper.unmount();
    });

    it('offers a retry when the stages could not be loaded', async () => {
        getJson.mockRejectedValueOnce(new Error('boom'));
        const wrapper = mount(StageSelect, { props: { modelValue: null } });
        await flushPromises();

        const retry = wrapper.find('button');
        expect(retry.text()).toBe('production.lots.stage_failed');

        getJson.mockResolvedValue({ data: [stage()] });
        await retry.trigger('click');
        await flushPromises();

        expect(wrapper.find('button').exists()).toBe(false);
        expect(wrapper.findAll('option').map((o) => o.text())).toContain(
            'Dyeing',
        );
        wrapper.unmount();
    });

    it('says nothing when a load it abandoned is rejected', async () => {
        const abort = Object.assign(new Error('aborted'), {
            name: 'AbortError',
        });
        getJson.mockRejectedValueOnce(abort);
        const wrapper = mount(StageSelect, { props: { modelValue: null } });
        await flushPromises();

        // an abandoned load must not paint an error over a screen that is still fine
        expect(wrapper.find('button').exists()).toBe(false);
        wrapper.unmount();
    });
});
