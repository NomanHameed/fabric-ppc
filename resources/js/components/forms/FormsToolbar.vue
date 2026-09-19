<script setup lang="ts">
import {
    ChevronLeft,
    ChevronRight,
    Eraser,
    FilePlus2,
    LogOut,
    Play,
    Printer,
    Save,
    Search,
    Trash2,
} from '@lucide/vue';
import type { Component } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useFormsKeys } from '@/composables/useFormsKeys';
import { keyLabel } from '@/forms/keymap';
import type { FormsAction } from '@/forms/keymap';
import { t } from '@/lib/i18n';

const props = withDefaults(
    defineProps<{
        disabled?: Partial<Record<FormsAction, boolean>>;
        mode?: 'normal' | 'enterQuery';
    }>(),
    { disabled: () => ({}), mode: 'normal' },
);

const emit = defineEmits<{ action: [action: FormsAction] }>();

const groups: { action: FormsAction; icon: Component }[][] = [
    [
        { action: 'save', icon: Save },
        { action: 'newRecord', icon: FilePlus2 },
        { action: 'deleteRecord', icon: Trash2 },
    ],
    [
        { action: 'enterQuery', icon: Search },
        { action: 'executeQuery', icon: Play },
    ],
    [
        { action: 'previousRecord', icon: ChevronLeft },
        { action: 'nextRecord', icon: ChevronRight },
    ],
    [
        { action: 'clear', icon: Eraser },
        { action: 'print', icon: Printer },
        { action: 'exit', icon: LogOut },
    ],
];

function fire(action: FormsAction): void {
    if (!props.disabled[action]) {
        emit('action', action);
    }
}

useFormsKeys(
    Object.fromEntries(
        (
            [
                'save',
                'newRecord',
                'deleteRecord',
                'enterQuery',
                'executeQuery',
                'previousRecord',
                'nextRecord',
                'clear',
                'print',
                'exit',
            ] as FormsAction[]
        ).map((a) => [a, () => fire(a)]),
    ),
);
</script>

<template>
    <TooltipProvider :delay-duration="300">
        <div
            role="toolbar"
            class="bg-muted/40 flex flex-wrap items-center gap-1 rounded-md border px-2 py-1"
            :class="
                mode === 'enterQuery'
                    ? 'border-amber-500/60 bg-amber-50 dark:bg-amber-950/30'
                    : ''
            "
        >
            <template v-for="(group, gi) in groups" :key="gi">
                <span
                    v-if="gi > 0"
                    class="bg-border mx-1 h-5 w-px"
                    aria-hidden="true"
                />
                <Tooltip v-for="item in group" :key="item.action">
                    <TooltipTrigger as-child>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            class="size-8"
                            :disabled="disabled[item.action]"
                            :aria-label="t(`forms.actions.${item.action}`)"
                            @click="fire(item.action)"
                        >
                            <component :is="item.icon" class="size-4" />
                        </Button>
                    </TooltipTrigger>
                    <TooltipContent>
                        {{ t(`forms.actions.${item.action}`) }}
                        <span
                            v-if="keyLabel(item.action)"
                            class="text-muted-foreground ms-2"
                            >{{ keyLabel(item.action) }}</span
                        >
                    </TooltipContent>
                </Tooltip>
            </template>
            <span
                v-if="mode === 'enterQuery'"
                class="ms-auto text-xs font-medium text-amber-700 dark:text-amber-400"
            >
                {{ t('forms.query_mode') }}
            </span>
        </div>
    </TooltipProvider>
</template>
