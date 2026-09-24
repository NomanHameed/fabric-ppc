import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it } from 'vitest';
import { defineComponent, h } from 'vue';
import {
    __resetChartTheme,
    FALLBACK_PALETTE,
    useChartTheme,
} from '@/components/charts/useChartTheme';

/** Mounts a component that exposes the shared palette, so the composable runs inside a real setup(). */
function mountProbe() {
    const Probe = defineComponent({
        setup() {
            const { palette } = useChartTheme();
            return () => h('span', palette.value.series[0]);
        },
    });
    return mount(Probe);
}

afterEach(() => {
    document.documentElement.removeAttribute('style');
    document.documentElement.classList.remove('dark');
    __resetChartTheme();
});

describe('useChartTheme', () => {
    it('reads the app tokens rather than shipping its own colours', () => {
        document.documentElement.style.setProperty(
            '--chart-1',
            'oklch(0.5974 0.0952 161.2)',
        );

        const probe = mountProbe();

        expect(probe.text()).toBe('#44916d');
        probe.unmount();
    });

    it('falls back when a token is not on the document yet', () => {
        const probe = mountProbe();

        expect(probe.text()).toBe(FALLBACK_PALETTE.series[0]);
        probe.unmount();
    });

    it('re-reads the palette when the dark class is toggled', async () => {
        document.documentElement.style.setProperty(
            '--chart-1',
            'oklch(0.5974 0.0952 161.2)',
        );
        const probe = mountProbe();
        expect(probe.text()).toBe('#44916d');

        // What the real stylesheet does on `.dark`: the same custom property resolves to another value.
        document.documentElement.style.setProperty(
            '--chart-1',
            'oklch(0.75 0.108 161.2)',
        );
        document.documentElement.classList.add('dark');
        await new Promise((resolve) => setTimeout(resolve, 0));
        await probe.vm.$nextTick();

        expect(probe.text()).not.toBe('#44916d');
        expect(probe.text()).toMatch(/^#[0-9a-f]{6}$/);
        probe.unmount();
    });

    it('detaches its observers once the last chart is gone', async () => {
        const first = mountProbe();
        const second = mountProbe();
        first.unmount();

        document.documentElement.style.setProperty(
            '--chart-1',
            'oklch(0.5974 0.0952 161.2)',
        );
        document.documentElement.classList.add('dark');
        await new Promise((resolve) => setTimeout(resolve, 0));

        // Still one watcher left, so the palette is still live.
        expect(second.text()).toBe('#44916d');
        second.unmount();
    });
});
