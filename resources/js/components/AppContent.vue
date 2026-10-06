<script setup lang="ts">
import { computed } from 'vue';
import { SidebarInset } from '@/components/ui/sidebar';
import { cn } from '@/lib/utils';

type Props = {
    class?: string;
};

const props = defineProps<Props>();

/**
 * `min-w-0` is what stops a wide grid from widening the flex column and pushing the
 * whole page sideways; `overflow-x-clip` (not `hidden`, which would break the sticky
 * header and status line) catches anything that still overhangs.
 */
const className = computed(() =>
    cn('min-w-0 overflow-x-clip', props.class as string | undefined),
);
</script>

<template>
    <SidebarInset id="main-content" :class="className">
        <slot />
    </SidebarInset>
</template>
