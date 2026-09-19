<script setup lang="ts">
import { computed } from 'vue';
import { Label } from '@/components/ui/label';
import { statusLine } from '@/lib/statusLine';

const props = withDefaults(defineProps<{ label: string; for?: string; error?: string; hint?: string; span?: number }>(), { span: 3 });

const spanClass = computed(() => {
    const map: Record<number, string> = {
        1: 'md:col-span-1', 2: 'md:col-span-2', 3: 'md:col-span-3', 4: 'md:col-span-4', 5: 'md:col-span-5', 6: 'md:col-span-6',
        7: 'md:col-span-7', 8: 'md:col-span-8', 9: 'md:col-span-9', 10: 'md:col-span-10', 11: 'md:col-span-11', 12: 'md:col-span-12',
    };
    return `col-span-12 ${map[props.span] ?? 'md:col-span-3'}`;
});
</script>

<template>
    <div :class="spanClass" class="flex flex-col gap-1" @focusin="statusLine.setHint(hint ?? '')">
        <Label :for="props.for" class="text-xs text-muted-foreground">{{ label }}</Label>
        <slot />
        <p v-if="error" class="text-xs text-destructive">{{ error }}</p>
    </div>
</template>
