import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import SummaryBars from '@/components/charts/SummaryBars.vue';

/*
 * The plain-DOM reading of a chart: what a phone shows instead of an illegible 320px canvas, and what a screen
 * reader is given beside every canvas on a wide screen.
 *
 * Its one rule is that the *bar* may be clamped but the *number* may not. A stenter at 130% of its capacity is
 * the single most important thing on this screen: a bar cannot be drawn 130% of its own track, but rounding
 * the figure down to 100% would hide exactly the over-commitment the planner opened the page to find.
 */
type Row = {
    key: string;
    name: string;
    value: string;
    percent: number | null;
    tone?: 'brand' | 'over' | 'high' | 'good' | 'low' | 'unknown';
    note?: string;
};

function render(rows: Row[], emptyText = 'Nothing to show') {
    return mount(SummaryBars, { props: { rows, emptyText } });
}

/** The inline size each bar was actually drawn at. */
function widths(wrapper: ReturnType<typeof render>): string[] {
    return wrapper
        .findAll('li > div > div')
        .map((bar) => bar.attributes('style') ?? '');
}

describe('SummaryBars', () => {
    it('clamps the bar at 100% while the label still says 130%', () => {
        const wrapper = render([
            {
                key: 'S2',
                name: 'Stenter 2',
                value: '130%',
                percent: 130,
                tone: 'over',
            },
        ]);

        expect(widths(wrapper)[0]).toContain('100%');
        expect(wrapper.text()).toContain('130%');
        wrapper.unmount();
    });

    it('clamps a negative figure at zero rather than drawing a bar backwards', () => {
        const wrapper = render([
            { key: 'x', name: 'Odd', value: '-10%', percent: -10 },
        ]);

        expect(widths(wrapper)[0]).toContain('0%');
        wrapper.unmount();
    });

    it('draws a real figure at its own width', () => {
        const wrapper = render([
            { key: 'S1', name: 'Stenter 1', value: '60%', percent: 60 },
            { key: 'S3', name: 'Stenter 3', value: '0%', percent: 0 },
        ]);

        expect(widths(wrapper)[0]).toContain('60%');
        expect(widths(wrapper)[1]).toContain('0%');
        wrapper.unmount();
    });

    it('shows the note instead of a bar when the figure is not knowable', () => {
        const wrapper = render([
            {
                key: 'JU',
                name: 'Jumbo',
                value: 'Not set',
                percent: null,
                note: '300 m allocated; capacity not set',
            },
        ]);

        // A bar of any length would be a number nobody entered.
        expect(wrapper.findAll('li > div > div')).toHaveLength(0);
        expect(wrapper.text()).toContain('300 m allocated; capacity not set');
        expect(wrapper.text()).toContain('Not set');
        wrapper.unmount();
    });

    it('draws neither bar nor note when there is nothing to say either way', () => {
        const wrapper = render([
            { key: 'f', name: 'Produced', value: '1,200 m', percent: null },
        ]);

        expect(wrapper.findAll('li > div > div')).toHaveLength(0);
        expect(wrapper.text()).toContain('1,200 m');
        wrapper.unmount();
    });

    it('falls back to the brand tone for a tone it does not know', () => {
        const wrapper = render([
            {
                key: 'a',
                name: 'Known',
                value: '10%',
                percent: 10,
                tone: 'over',
            },
            {
                key: 'b',
                name: 'Unknown tone',
                value: '10%',
                percent: 10,
                tone: 'not-a-tone' as unknown as Row['tone'],
            },
            { key: 'c', name: 'No tone at all', value: '10%', percent: 10 },
        ]);
        const bars = wrapper.findAll('li > div > div');

        expect(bars[0].classes()).toContain('bg-destructive');
        // Never an unstyled, invisible bar: an unrecognised tone reads as the brand.
        expect(bars[1].classes()).toContain('bg-primary');
        expect(bars[2].classes()).toContain('bg-primary');
        wrapper.unmount();
    });

    it('says why it is empty rather than showing an empty list', () => {
        const wrapper = render([], 'Nothing was recorded in this range.');

        expect(wrapper.find('ul').exists()).toBe(false);
        expect(wrapper.text()).toBe('Nothing was recorded in this range.');
        wrapper.unmount();
    });
});
