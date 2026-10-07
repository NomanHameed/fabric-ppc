import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import StageProcessSelect from '@/components/stages/StageProcessSelect.vue';
import type { StageProcess } from '@/types/stages';

/*
 * The process select follows the stage above it (spec §17): its options are that stage's processes, read
 * from the API, and a value belonging to another stage is never left selected — the pair the form submits
 * has to be a pair.
 */

function process(over: Partial<StageProcess> = {}): StageProcess {
    return {
        id: 11,
        stage_id: 1,
        code: 'DYEING',
        name: 'Dyeing',
        description: null,
        sort_order: 10,
        quantity_type: 'wip',
        is_wip: true,
        is_finished: false,
        is_dispatch: false,
        status_aliases: ['DYEING'],
        is_active: true,
        ...over,
    };
}

const getJson = vi.fn();
vi.mock('@/lib/api', () => ({
    getJson: (...args: unknown[]) => getJson(...args),
    isAbortError: (e: unknown) =>
        (e as { name?: string })?.name === 'AbortError',
}));

describe('StageProcessSelect', () => {
    afterEach(() => {
        getJson.mockReset();
    });

    it('asks for nothing and says so while no stage is chosen', async () => {
        const wrapper = mount(StageProcessSelect, {
            props: { modelValue: null, stageId: null },
        });
        await flushPromises();

        expect(getJson).not.toHaveBeenCalled();
        expect(wrapper.find('option').text()).toBe(
            'production.lots.stage_pick_stage_first',
        );
        expect(wrapper.find('select').attributes('disabled')).toBeDefined();
        wrapper.unmount();
    });

    it('loads the processes of the chosen stage', async () => {
        getJson.mockResolvedValue({
            data: [process(), process({ id: 12, name: 'Over-dyeing' })],
        });
        const wrapper = mount(StageProcessSelect, {
            props: { modelValue: null, stageId: 1 },
        });
        await flushPromises();

        expect(getJson.mock.calls[0][0]).toBe('/api/stages/1/processes');
        expect(wrapper.findAll('option').map((o) => o.text())).toEqual([
            'production.lots.stage_process_pick',
            'Dyeing',
            'Over-dyeing',
        ]);
        wrapper.unmount();
    });

    it('reloads and clears the value when the stage changes under it', async () => {
        getJson.mockResolvedValue({ data: [process({ id: 11 })] });
        const wrapper = mount(StageProcessSelect, {
            props: { modelValue: 11, stageId: 1 },
        });
        await flushPromises();
        expect(wrapper.emitted('update:modelValue')).toBeUndefined();

        getJson.mockResolvedValue({
            data: [process({ id: 21, stage_id: 2, name: 'Printing' })],
        });
        await wrapper.setProps({ stageId: 2 });
        await flushPromises();

        expect(getJson.mock.calls[1][0]).toBe('/api/stages/2/processes');
        // 11 is not a process of stage 2: the pair would not be a pair
        expect(wrapper.emitted('update:modelValue')).toEqual([[null]]);
        wrapper.unmount();
    });

    it('keeps a value the new list does contain', async () => {
        getJson.mockResolvedValue({ data: [process({ id: 11 })] });
        const wrapper = mount(StageProcessSelect, {
            props: { modelValue: 11, stageId: 1 },
        });
        await flushPromises();

        getJson.mockResolvedValue({
            data: [process({ id: 11 }), process({ id: 12 })],
        });
        await wrapper.setProps({ stageId: 1 });
        await flushPromises();

        expect(wrapper.emitted('update:modelValue')).toBeUndefined();
        wrapper.unmount();
    });

    it("keeps the record's own process when it is no longer active", async () => {
        getJson.mockResolvedValue({ data: [process({ id: 11 })] });
        const wrapper = mount(StageProcessSelect, {
            props: {
                modelValue: 99,
                stageId: 1,
                fallback: { id: 99, name: 'Retired process' },
            },
        });
        await flushPromises();

        expect(wrapper.findAll('option').map((o) => o.text())).toContain(
            'Retired process',
        );
        expect(wrapper.emitted('update:modelValue')).toBeUndefined();
        wrapper.unmount();
    });

    it('emits the chosen id as a number', async () => {
        getJson.mockResolvedValue({ data: [process({ id: 11 })] });
        const wrapper = mount(StageProcessSelect, {
            props: { modelValue: null, stageId: 1 },
        });
        await flushPromises();

        await wrapper.find('select').setValue('11');

        expect(wrapper.emitted('update:modelValue')).toEqual([[11]]);
        wrapper.unmount();
    });

    it('offers a retry when the processes could not be loaded', async () => {
        getJson.mockRejectedValueOnce(new Error('boom'));
        const wrapper = mount(StageProcessSelect, {
            props: { modelValue: null, stageId: 1 },
        });
        await flushPromises();

        expect(wrapper.find('button').text()).toBe(
            'production.lots.stage_failed',
        );

        getJson.mockResolvedValue({ data: [process()] });
        await wrapper.find('button').trigger('click');
        await flushPromises();

        expect(wrapper.find('button').exists()).toBe(false);
        wrapper.unmount();
    });
});
