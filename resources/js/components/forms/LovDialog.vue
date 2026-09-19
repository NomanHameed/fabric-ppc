<script setup lang="ts">
import { useDebounceFn } from '@vueuse/core';
import { DialogContent as DialogContentPrimitive } from 'reka-ui';
import { nextTick, ref, watch } from 'vue';
import { Dialog, DialogHeader, DialogOverlay, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { useFormsKeys } from '@/composables/useFormsKeys';
import { t } from '@/lib/i18n';
import { fetchLov } from '@/lib/lov';
import type { LovResponse, LovRow } from '@/lib/lov';
import { cn } from '@/lib/utils';

// NB: unlike the other shadcn dialog usages in this app, this dialog renders its
// content WITHOUT `DialogPortal` (reka-ui's `<DialogContent>` primitive directly,
// not the `@/components/ui/dialog` wrapper). `DialogPortal` teleports through a
// reka-ui-internal component that is itself named "Teleport" — jsdom handles a
// real `<teleport to="body">` fine, but a test that stubs the *native* Vue
// Teleport by name (`global: { stubs: { teleport: true } }`, as this component's
// spec does) also matches reka-ui's identically-named wrapper and swallows its
// slot content entirely (Vue Test Utils only forces `renderStubDefaultSlot` for
// the built-in Teleport/KeepAlive/Transition stubs, not for name-matched ones).
// Skipping the portal keeps identical visual behaviour (the content is
// `position: fixed`, so its DOM position doesn't matter) while staying
// testable. See resources/js/components/forms/__tests__/LovDialog.spec.ts.

const props = defineProps<{ name: string; filters?: Record<string, string | number | null>; initialQuery?: string; title?: string }>();
const open = defineModel<boolean>('open', { default: false });
const emit = defineEmits<{ select: [row: LovRow] }>();

const query = ref(props.initialQuery ?? '');
const result = ref<LovResponse>({ columns: [], data: [] });
const active = ref(0);
const loading = ref(false);
const input = ref<InstanceType<typeof Input> | null>(null);

const load = useDebounceFn(async () => {
    loading.value = true;
    try {
        result.value = await fetchLov(props.name, { q: query.value, filters: props.filters });
        active.value = 0;
    } finally {
        loading.value = false;
    }
}, 200);

watch(open, async (isOpen) => {
    if (isOpen) {
        query.value = props.initialQuery ?? '';
        await load();
        await nextTick();
        (input.value?.$el as HTMLInputElement | undefined)?.focus();
    }
}, { immediate: true });

watch(query, () => load());

// Capture form keys while the dialog is open, so the form underneath doesn't react.
useFormsKeys({});

function onKeydown(e: KeyboardEvent): void {
    const count = result.value.data.length;
    if (e.key === 'ArrowDown') {
        e.preventDefault();
        active.value = Math.min(active.value + 1, count - 1);
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        active.value = Math.max(active.value - 1, 0);
    } else if (e.key === 'Enter') {
        e.preventDefault();
        const row = result.value.data[active.value];
        if (row) {
            choose(row);
        }
    }
}

function choose(row: LovRow): void {
    emit('select', row);
    open.value = false;
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogOverlay />
        <DialogContentPrimitive
            :class="cn(
                'bg-background fixed top-[50%] left-[50%] z-50 grid w-full max-w-[calc(100%-2rem)] translate-x-[-50%] translate-y-[-50%] gap-4 rounded-lg border p-6 shadow-lg duration-200 sm:max-w-2xl',
            )"
        >
            <DialogHeader>
                <DialogTitle>{{ title ?? t('forms.actions.lov') }}</DialogTitle>
            </DialogHeader>
            <Input
                ref="input"
                v-model="query"
                data-lov-search
                :placeholder="t('forms.search')"
                autocomplete="off"
                @keydown="onKeydown"
            />
            <div class="max-h-80 overflow-auto rounded border">
                <table class="w-full text-sm tabular-nums">
                    <thead class="sticky top-0 bg-muted text-xs text-muted-foreground">
                        <tr>
                            <th v-for="c in result.columns" :key="c.key" class="px-2 py-1 text-start font-medium">{{ c.label }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="(row, i) in result.data"
                            :key="row.id"
                            :aria-selected="i === active"
                            class="cursor-pointer border-t"
                            :class="i === active ? 'bg-primary/10' : 'hover:bg-muted/60'"
                            @mouseenter="active = i"
                            @click="choose(row)"
                        >
                            <td v-for="c in result.columns" :key="c.key" class="px-2 py-1">{{ row[c.key] }}</td>
                        </tr>
                        <tr v-if="!loading && result.data.length === 0">
                            <td :colspan="result.columns.length || 1" class="px-2 py-3 text-center text-muted-foreground">
                                {{ t('forms.no_records') }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </DialogContentPrimitive>
    </Dialog>
</template>
