<script setup lang="ts">
import { computed } from 'vue';

/**
 * The fallback a chart collapses to when the screen is too narrow to read one: a labelled list with a bar per
 * row, drawn in plain DOM.
 *
 * A 320px canvas with eight stacked series and a date axis is an illegible smear, so on a phone the same
 * numbers are shown as totals instead of as a time series. It is also the accessible reading of the chart
 * beside it on wider screens — real text, selectable and screen-readable, where the canvas is not.
 */
type Row = {
    key: string;
    name: string;
    value: string;
    /** 0–100+; the bar's width is clamped at 100 but the value shown is not. */
    percent: number | null;
    tone?: 'brand' | 'over' | 'high' | 'good' | 'low' | 'unknown';
    /** Shown in place of a bar when the figure is not knowable (e.g. capacity not on file). */
    note?: string;
};

const props = withDefaults(defineProps<{ rows: Row[]; emptyText?: string }>(), {
    emptyText: '',
});

const TONES: Record<string, string> = {
    brand: 'bg-primary',
    over: 'bg-destructive',
    high: 'bg-warning',
    good: 'bg-success',
    low: 'bg-info',
    unknown: 'bg-muted-foreground',
};

const bars = computed(() =>
    props.rows.map((row) => ({
        ...row,
        width:
            row.percent === null ? 0 : Math.min(100, Math.max(0, row.percent)),
        tone: TONES[row.tone ?? 'brand'] ?? TONES.brand,
    })),
);
</script>

<template>
    <p
        v-if="bars.length === 0"
        class="text-muted-foreground py-6 text-center text-sm"
    >
        {{ emptyText }}
    </p>
    <ul v-else class="flex flex-col gap-3">
        <li v-for="row in bars" :key="row.key" class="flex flex-col gap-1">
            <div class="flex items-baseline justify-between gap-3 text-sm">
                <span class="min-w-0 truncate">{{ row.name }}</span>
                <span class="shrink-0 font-medium tabular-nums">{{
                    row.value
                }}</span>
            </div>
            <div
                v-if="row.percent !== null"
                class="bg-muted h-2 w-full overflow-hidden rounded-full"
            >
                <div
                    class="h-full rounded-full"
                    :class="row.tone"
                    :style="{ inlineSize: `${row.width}%` }"
                ></div>
            </div>
            <p v-else-if="row.note" class="text-muted-foreground text-xs">
                {{ row.note }}
            </p>
        </li>
    </ul>
</template>
