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
        class="bg-muted/70 sticky bottom-0 z-20 flex h-7 items-center gap-4 border-t px-3 text-xs tabular-nums backdrop-blur"
        aria-live="polite"
    >
        <span class="text-muted-foreground min-w-28">{{ record }}</span>
        <span
            class="truncate"
            :class="{
                'text-destructive': s.kind === 'error',
                'text-emerald-700 dark:text-emerald-400': s.kind === 'success',
            }"
            >{{ s.message }}</span
        >
        <span class="text-muted-foreground ms-auto truncate">{{ s.hint }}</span>
        <span
            v-if="s.dirty"
            class="rounded bg-amber-500/15 px-1.5 text-amber-700 dark:text-amber-400"
            >{{ t('forms.unsaved') }}</span
        >
    </footer>
</template>
