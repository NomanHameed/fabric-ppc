<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Search } from '@lucide/vue';
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { Input } from '@/components/ui/input';
import { matchAction } from '@/forms/keymap';
import { t } from '@/lib/i18n';

/**
 * The top-bar Lot / PO search (spec §4.1): a lot number, its last 4 digits, or a PO number; Enter opens the
 * inquiry (or the chooser when several lots share the number). Ctrl+K focuses the box from anywhere: it is not
 * in the Forms key map (F7–F10, Ctrl+S/Q/P, Ctrl+↓/Del, Page Up/Down), so no form shortcut is shadowed, and
 * `matchAction` is checked first so a future keymap change cannot collide silently. The shortcut stays out
 * while a dialog or alert dialog is open (the dialog owns the focus). The box is shown at every width, so
 * Ctrl+K always has something to focus.
 */
const q = ref('');
const input = ref<InstanceType<typeof Input> | null>(null);

/** The exact search box element (the Input primitive renders one `<input>`). */
function element(): HTMLInputElement | null {
    const el = input.value?.$el as HTMLElement | undefined;
    return el instanceof HTMLInputElement
        ? el
        : (el?.querySelector('input') ?? null);
}

function submit(): void {
    const value = q.value.trim();
    if (value === '') {
        return;
    }
    router.get('/search', { q: value });
}

function onKeydown(e: KeyboardEvent): void {
    if (
        !(e.ctrlKey || e.metaKey) ||
        e.altKey ||
        e.shiftKey ||
        e.key.toUpperCase() !== 'K' ||
        matchAction(e) !== null ||
        document.querySelector('[role=dialog],[role=alertdialog]') !== null
    ) {
        return;
    }
    e.preventDefault();
    const el = element();
    el?.focus();
    el?.select();
}

onMounted(() => window.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown));
</script>

<template>
    <form
        role="search"
        class="relative flex items-center"
        @submit.prevent="submit"
    >
        <Search
            class="text-muted-foreground pointer-events-none absolute start-2 size-4"
            aria-hidden="true"
        />
        <Input
            ref="input"
            v-model="q"
            type="search"
            name="q"
            inputmode="numeric"
            autocomplete="off"
            data-global-search
            class="h-8 w-32 ps-8 text-sm tabular-nums sm:w-40 md:w-56"
            :placeholder="t('inquiry.search.placeholder')"
            :aria-label="t('inquiry.search.label')"
            :title="`${t('inquiry.search.label')} (${t('inquiry.search.shortcut')})`"
        />
    </form>
</template>
