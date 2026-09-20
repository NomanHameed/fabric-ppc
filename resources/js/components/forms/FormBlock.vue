<script setup lang="ts">
defineProps<{ title?: string }>();

const FOCUSABLE =
    'input:not([disabled]):not([type=hidden]):not([tabindex="-1"]), select:not([disabled]):not([tabindex="-1"]), textarea:not([disabled]):not([tabindex="-1"]), button:not([disabled]):not([tabindex="-1"])';

/** Oracle Forms: Enter (like Tab) moves to the next field; textareas, grids, dialogs and buttons keep Enter. */
function onKeydown(e: KeyboardEvent): void {
    const target = e.target as HTMLElement | null;
    if (e.key !== 'Enter' || e.shiftKey || e.ctrlKey || e.altKey || !target) {
        return;
    }
    if (
        target.tagName === 'TEXTAREA' ||
        target.tagName === 'BUTTON' ||
        target.closest('.ag-root-wrapper, [role=dialog]')
    ) {
        return;
    }
    const fields = Array.from(
        (e.currentTarget as HTMLElement).querySelectorAll<HTMLElement>(
            FOCUSABLE,
        ),
    );
    const i = fields.indexOf(target);
    if (i >= 0 && i < fields.length - 1) {
        e.preventDefault();
        fields[i + 1].focus();
    }
}
</script>

<template>
    <section class="bg-card rounded-md border" @keydown="onKeydown">
        <header
            v-if="title"
            class="text-muted-foreground border-b px-3 py-1.5 text-xs font-semibold tracking-wide uppercase"
        >
            {{ title }}
        </header>
        <div class="grid grid-cols-12 gap-x-3 gap-y-2 p-3">
            <slot />
        </div>
    </section>
</template>
