<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { t } from '@/lib/i18n';

/**
 * Mark plus wordmark. Two root nodes on purpose: every caller is a flex row
 * (a sidebar menu button, a header link), so the mark and the type stay siblings
 * of that row rather than nesting an extra box inside it.
 *
 * The wordmark is type, not an image: `ALBEEN` in `--brand-strong` (the text-safe
 * green, >= 4.5:1 on background, card and sidebar in both modes) over `TEXTILES`
 * in muted grey. Collapsing the sidebar to icons hides the type and leaves the mark.
 */
withDefaults(
    defineProps<{
        /** Hide the wordmark and show the mark alone (tight headers, icon rails). */
        markOnly?: boolean;
    }>(),
    { markOnly: false },
);
</script>

<template>
    <AppLogoIcon class="size-8 shrink-0" />
    <div
        v-if="!markOnly"
        class="grid min-w-0 flex-1 text-start leading-none group-data-[collapsible=icon]:hidden"
    >
        <span
            class="text-brand-strong truncate text-sm font-semibold tracking-[0.16em]"
            >{{ t('app.brand.wordmark') }}</span
        >
        <span
            class="text-muted-foreground mt-0.5 truncate text-[0.625rem] font-medium tracking-[0.3em]"
            >{{ t('app.brand.wordmark_sub') }}</span
        >
    </div>
</template>
