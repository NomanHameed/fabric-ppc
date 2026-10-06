import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

/*
 * The prompt has to be easy to get rid of: it appears only for a terminal nobody has named, it goes away for
 * good once dismissed on that terminal, and a failed probe leaves the page exactly as it was. None of that is
 * visible from the server, so it is proved here.
 */
const put = vi.fn();

vi.mock('@inertiajs/vue3', () => ({
    router: { put: (...args: unknown[]) => put(...args) },
}));

type Probe = {
    device_id: string;
    computer_name: string | null;
    named: boolean;
};

function stubFetch(body: Probe | null, ok = true): void {
    vi.stubGlobal(
        'fetch',
        vi.fn(() =>
            Promise.resolve({
                ok,
                json: () => Promise.resolve(body),
            } as Response),
        ),
    );
}

async function mountPrompt() {
    const { default: DeviceNamePrompt } =
        await import('@/components/activity/DeviceNamePrompt.vue');
    const wrapper = mount(DeviceNamePrompt, { attachTo: document.body });
    await flushPromises();
    return wrapper;
}

beforeEach(() => {
    put.mockClear();
    window.localStorage.clear();
});

afterEach(() => {
    vi.unstubAllGlobals();
    document.body.innerHTML = '';
});

describe('DeviceNamePrompt', () => {
    it('appears only for a terminal that has no name', async () => {
        stubFetch({ device_id: 'DEV1', computer_name: null, named: false });
        const unnamed = await mountPrompt();
        expect(unnamed.find('[data-test="device-name-prompt"]').exists()).toBe(
            true,
        );
        unnamed.unmount();

        stubFetch({ device_id: 'DEV1', computer_name: 'LAB', named: true });
        const named = await mountPrompt();
        expect(named.find('[data-test="device-name-prompt"]').exists()).toBe(
            false,
        );
        named.unmount();
    });

    it('stays away once dismissed on this terminal', async () => {
        stubFetch({ device_id: 'DEV1', computer_name: null, named: false });
        const first = await mountPrompt();
        await first.get('[data-test="dismiss-device-prompt"]').trigger('click');
        await flushPromises();
        expect(first.find('[data-test="device-name-prompt"]').exists()).toBe(
            false,
        );
        first.unmount();

        const second = await mountPrompt();
        expect(second.find('[data-test="device-name-prompt"]').exists()).toBe(
            false,
        );
        second.unmount();
    });

    it('submits the trimmed name and nothing when it is blank', async () => {
        stubFetch({ device_id: 'DEV1', computer_name: null, named: false });
        const wrapper = await mountPrompt();

        await wrapper.get('#device-name-prompt-input').setValue('   ');
        await wrapper.get('[data-test="save-device-name"]').trigger('click');
        expect(put).not.toHaveBeenCalled();

        await wrapper.get('#device-name-prompt-input').setValue('  LAB-BENCH ');
        await wrapper.get('[data-test="save-device-name"]').trigger('click');
        await flushPromises();

        expect(put).toHaveBeenCalledTimes(1);
        expect(put.mock.calls[0][0]).toBe('/devices/name');
        expect(put.mock.calls[0][1]).toEqual({ computer_name: 'LAB-BENCH' });
        wrapper.unmount();
    });

    it('shows nothing at all when the probe fails', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(() => Promise.reject(new Error('offline'))),
        );
        const wrapper = await mountPrompt();

        expect(wrapper.find('[data-test="device-name-prompt"]').exists()).toBe(
            false,
        );
        wrapper.unmount();
    });
});
