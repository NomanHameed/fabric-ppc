import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import { setTranslations } from '@/lib/i18n';

/*
 * A lot's story, read from `GET /production/lots/{lot}/timeline`.
 *
 * Two things this screen must keep straight. The filters are the server's: a type toggle is a new request
 * carrying `types[]`, never a slice of a list the browser already holds, because the endpoint's own default is
 * "everything". And the request is *bounded*: the endpoint returns every event when no limit is given, and a
 * lot that has been reprocessed a few times carries hundreds of them — each an `<li>` with a rail, a badge and
 * three lines of text. Asking for a page and saying so is honest; rendering an unbounded list is not.
 */
const getJson = vi.hoisted(() => vi.fn());

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ name: 'Head', setup: () => () => null }),
    Link: defineComponent({
        name: 'Link',
        setup:
            (_props, { slots }) =>
            () =>
                h('a', slots.default?.()),
    }),
    router: { get: vi.fn(), on: () => () => {} },
}));

vi.mock('@/lib/api', () => ({
    getJson: (...args: unknown[]) => getJson(...args),
    isAbortError: () => false,
}));

vi.mock('@/lib/can', () => ({ can: () => true }));

vi.mock('@/components/forms/LovField.vue', () => ({
    default: defineComponent({ name: 'LovField', setup: () => () => null }),
}));

function event(i: number, type = 'moved') {
    return {
        id: `movement:${i}`,
        type,
        title: `Moved ${i} m from Issued to Dyeing`,
        occurred_at: '2026-09-20T09:30:00+05:00',
        from: 'Issued to floor',
        to: 'Dyeing',
        qty: i,
        actor_id: 1,
        actor: 'Asif',
        note: null,
    };
}

function events(count: number) {
    return {
        lot: { id: 7, lot_no: '640101', state: 'open' },
        events: Array.from({ length: count }, (_, i) => event(i + 1)),
    };
}

const lot = {
    id: 7,
    lot_no: '640101',
    po_no: 4321,
    contract_id: 3,
    party: 'Al-Karam',
    quality: 'Poplin 60x60',
    design: null,
    colour: 'Navy',
    colour_status: 'approved',
    po_type: 'local',
    state: 'open',
    current_label: 'Dyeing',
    greige_qty: 1000,
    wip_qty: 400,
    finished_qty: 500,
    dispatched_qty: 100,
};

async function mountTimeline(response = events(3)) {
    getJson.mockResolvedValue(response);
    const { default: LotTimeline } =
        await import('@/pages/production/LotTimeline.vue');
    const wrapper = mount(LotTimeline, {
        attachTo: document.body,
        props: { lot, types: ['moved', 'dispatched', 'loss'] },
    });
    await flushPromises();

    return wrapper;
}

/** The query string of the most recent request, as parameters. */
function lastQuery(): URLSearchParams {
    const url = String(getJson.mock.calls.at(-1)?.[0] ?? '');

    return new URLSearchParams(url.slice(url.indexOf('?') + 1));
}

/**
 * Only the one line whose wording is under test. Every other key falls through to itself, which is what makes
 * the assertions above readable as "this key, not that one".
 */
const DICT = {
    timeline: {
        screen: { capped: 'Showing the most recent :n; there may be more.' },
    },
};

beforeEach(() => {
    getJson.mockReset();
    setTranslations('en', 'ltr', DICT, 'test');
});
afterEach(() => {
    document.body.innerHTML = '';
});

describe('LotTimeline — the type filters', () => {
    it('asks the server for one type, and repeats the key for each further type', async () => {
        const wrapper = await mountTimeline();
        const buttons = wrapper.findAll('button');
        const moved = buttons.find((b) => b.text() === 'timeline.types.moved');
        const loss = buttons.find((b) => b.text() === 'timeline.types.loss');

        await moved?.trigger('click');
        await flushPromises();
        expect(lastQuery().getAll('types[]')).toEqual(['moved']);

        await loss?.trigger('click');
        await flushPromises();
        expect(lastQuery().getAll('types[]')).toEqual(['moved', 'loss']);

        // Toggling the same one off removes only that type.
        await moved?.trigger('click');
        await flushPromises();
        expect(lastQuery().getAll('types[]')).toEqual(['loss']);
        wrapper.unmount();
    });

    it('sends no types at all for "everything", which is the endpoint\'s own default', async () => {
        const wrapper = await mountTimeline();
        const buttons = wrapper.findAll('button');
        const moved = buttons.find((b) => b.text() === 'timeline.types.moved');
        const all = buttons.find(
            (b) => b.text() === 'timeline.screen.all_types',
        );

        await moved?.trigger('click');
        await flushPromises();
        expect(lastQuery().getAll('types[]')).toEqual(['moved']);

        await all?.trigger('click');
        await flushPromises();
        expect(lastQuery().getAll('types[]')).toEqual([]);
        wrapper.unmount();
    });

    it('never filters in the browser: a filter change is always a fresh request', async () => {
        const wrapper = await mountTimeline();
        const before = getJson.mock.calls.length;

        await wrapper
            .findAll('button')
            .find((b) => b.text() === 'timeline.types.dispatched')
            ?.trigger('click');
        await flushPromises();

        expect(getJson.mock.calls.length).toBe(before + 1);
        wrapper.unmount();
    });
});

describe('LotTimeline — the locale', () => {
    it('re-asks the server, because every sentence is written server-side', async () => {
        const wrapper = await mountTimeline();
        const before = getJson.mock.calls.length;

        setTranslations('ur', 'rtl', DICT, 'test');
        await flushPromises();

        expect(getJson.mock.calls.length).toBe(before + 1);
        wrapper.unmount();
    });
});

describe('LotTimeline — the bounded request', () => {
    it('asks for a page, never for everything', async () => {
        const wrapper = await mountTimeline();

        expect(lastQuery().get('limit')).toBe('200');
        wrapper.unmount();
    });

    it('says so when the page came back full, and offers the rest', async () => {
        const wrapper = await mountTimeline(events(200));

        expect(wrapper.find('[data-test="timeline-capped"]').exists()).toBe(
            true,
        );
        expect(wrapper.get('[data-test="timeline-capped"]').text()).toContain(
            '200',
        );
        expect(wrapper.find('[data-test="timeline-load-all"]').exists()).toBe(
            true,
        );
        wrapper.unmount();
    });

    it('says nothing when the lot simply has fewer events than the page', async () => {
        const wrapper = await mountTimeline(events(3));

        expect(wrapper.find('[data-test="timeline-capped"]').exists()).toBe(
            false,
        );
        expect(wrapper.find('[data-test="timeline-load-all"]').exists()).toBe(
            false,
        );
        wrapper.unmount();
    });

    it('re-requests at the endpoint\'s ceiling on "load all", and then stops offering', async () => {
        const wrapper = await mountTimeline(events(200));

        getJson.mockResolvedValue(events(350));
        await wrapper.get('[data-test="timeline-load-all"]').trigger('click');
        await flushPromises();

        expect(lastQuery().get('limit')).toBe('500');
        expect(wrapper.find('[data-test="timeline-load-all"]').exists()).toBe(
            false,
        );
        expect(wrapper.find('[data-test="timeline-capped"]').exists()).toBe(
            false,
        );
        wrapper.unmount();
    });

    it('goes back to a page when the question changes', async () => {
        const wrapper = await mountTimeline(events(200));

        await wrapper.get('[data-test="timeline-load-all"]').trigger('click');
        await flushPromises();
        expect(lastQuery().get('limit')).toBe('500');

        await wrapper
            .findAll('button')
            .find((b) => b.text() === 'timeline.types.moved')
            ?.trigger('click');
        await flushPromises();

        expect(lastQuery().get('limit')).toBe('200');
        wrapper.unmount();
    });
});
