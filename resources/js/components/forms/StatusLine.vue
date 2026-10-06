<script setup lang="ts">
import { computed } from 'vue';
import { t } from '@/lib/i18n';
import { statusLine } from '@/lib/statusLine';

const s = statusLine.state;
const record = computed(() =>
    s.recordCount > 0
        ? t('forms.record', { n: s.recordIndex, m: s.recordCount })
        : t('forms.no_records'),
);
</script>

<template>
    <footer
        class="bg-muted/70 sticky bottom-0 z-20 flex min-h-8 flex-wrap items-center gap-x-3 gap-y-0.5 border-t px-3 py-1 text-xs tabular-nums backdrop-blur sm:h-7 sm:flex-nowrap sm:gap-x-4 sm:py-0"
        aria-live="polite"
    >
        <span class="text-muted-foreground shrink-0 sm:min-w-28">{{
            record
        }}</span>
        <span
            class="min-w-0 flex-1 truncate"
            :class="{
                'text-destructive': s.kind === 'error',
                'text-success-on-surface': s.kind === 'success',
            }"
            >{{ s.message }}</span
        >
        <!--
          The hint is operational data, not a function-key legend: producers put stock
          figures, availability and the reason a lot is blocked in here. So it is never
          hidden — on a phone it wraps onto its own full-width line below the message,
          and only from `sm` up does it share the row, splitting the free space evenly
          with the message (both `flex-1`, so neither can squeeze the other to nothing)
          and truncating with the full text on `title`. `text-end` keeps it against the
          trailing edge exactly as the old `ms-auto` did, in either direction.
          `hidden` would also have dropped it out of this `aria-live` region.
        -->
        <span
            v-if="s.hint"
            :title="s.hint"
            class="text-muted-foreground order-last w-full min-w-0 break-words sm:order-none sm:w-auto sm:flex-1 sm:truncate sm:text-end"
            >{{ s.hint }}</span
        >
        <span
            v-if="s.dirty"
            class="bg-warning-surface text-warning-on-surface shrink-0 rounded px-1.5"
            >{{ t('forms.unsaved') }}</span
        >
    </footer>
</template>
