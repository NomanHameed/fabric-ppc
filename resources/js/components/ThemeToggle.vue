<script setup lang="ts">
import { Moon, Sun } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { useAppearance } from '@/composables/useAppearance';
import { t } from '@/lib/i18n';

const { appearance, updateAppearance } = useAppearance();

function toggle(): void {
    const dark = appearance.value === 'dark'
        || (appearance.value === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
    updateAppearance(dark ? 'light' : 'dark');
}
</script>

<template>
    <Button variant="ghost" size="icon" class="size-8" :aria-label="t('app.theme')" @click="toggle">
        <Sun class="size-4 dark:hidden" />
        <Moon class="hidden size-4 dark:block" />
    </Button>
</template>
