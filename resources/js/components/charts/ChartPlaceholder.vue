<script setup lang="ts">
import { computed } from 'vue';

/**
 * What stands in the chart's place while the ECharts chunk is still on the wire.
 *
 * ECharts is loaded lazily (`defineAsyncComponent` in Dashboard.vue and Utilisation.vue), so for the first
 * few hundred milliseconds there is nothing to draw. A zero-height nothing would let the cards below jump up
 * and then back down as each chart resolves, which on a dashboard of four charts reads as the page breaking.
 * Vue hands the loading component the same props as the real one, so this reserves the chart's *actual*
 * height rather than a guess at it.
 *
 * It is `aria-hidden` and carries no text: the `SummaryBars` list beside every chart is the accessible
 * reading, and it is already rendered by the time this appears.
 */
const props = withDefaults(defineProps<{ height?: number }>(), { height: 260 });

const style = computed(() => ({ height: `${props.height}px` }));
</script>

<template>
    <div
        class="bg-muted/40 w-full animate-pulse rounded-md"
        :style="style"
        aria-hidden="true"
        data-test="chart-placeholder"
    ></div>
</template>
