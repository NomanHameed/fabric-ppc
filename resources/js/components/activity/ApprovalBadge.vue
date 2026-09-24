<script setup lang="ts">
import { computed } from 'vue';
import { t } from '@/lib/i18n';

/**
 * A signup's approval state as a chip. The three states use the semantic surface pairs, never the brand green:
 * `approved` must not read as "brand chrome" and `pending` must not read as a failure.
 */
const props = defineProps<{ status: string }>();

const tone = computed(() => {
    switch (props.status) {
        case 'approved':
            return 'bg-success-surface text-success-on-surface';
        case 'rejected':
            return 'bg-destructive-surface text-destructive-on-surface';
        default:
            return 'bg-warning-surface text-warning-on-surface';
    }
});
</script>

<template>
    <span
        class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium whitespace-nowrap"
        :class="tone"
        >{{ t(`approval.statuses.${status}`) }}</span
    >
</template>
