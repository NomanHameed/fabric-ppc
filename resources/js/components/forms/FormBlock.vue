<script setup lang="ts">
defineProps<{ title?: string }>();
</script>

<template>
    <section class="rounded-md border bg-card">
        <header v-if="title" class="border-b px-3 py-1.5 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
            {{ title }}
        </header>
        <div class="grid grid-cols-12 gap-x-3 gap-y-2 p-3">
            <slot />
        </div>
    </section>
</template>
