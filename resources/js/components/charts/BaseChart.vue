<script setup lang="ts">
import type { EChartsOption } from 'echarts';
import { computed } from 'vue';
import { VChart } from './echarts';

/**
 * One chart, sized and labelled.
 *
 * The option is passed in whole from the page, which recomputes it whenever the palette, the locale direction
 * or the data changes — that is what makes a chart follow the light/dark toggle instead of keeping the colours
 * ECharts cached at first paint.
 *
 * `autoresize` is on so a chart reflows when the sidebar collapses or the phone rotates; the wrapper has an
 * explicit height because a canvas in a flex column would otherwise collapse to nothing.
 */
const props = withDefaults(
    defineProps<{
        option: EChartsOption;
        /** Read out by a screen reader in place of the canvas, which has no accessible content of its own. */
        label: string;
        height?: number;
        empty?: boolean;
        emptyText?: string;
    }>(),
    { height: 260, empty: false, emptyText: '' },
);

const style = computed(() => ({ height: `${props.height}px` }));
</script>

<template>
    <div
        v-if="empty"
        class="text-muted-foreground flex items-center justify-center rounded-md border border-dashed px-4 text-center text-sm"
        :style="style"
    >
        {{ emptyText }}
    </div>
    <div v-else class="w-full" :style="style" role="img" :aria-label="label">
        <VChart
            class="h-full w-full"
            :option="option"
            autoresize
            aria-hidden="true"
        />
    </div>
</template>
