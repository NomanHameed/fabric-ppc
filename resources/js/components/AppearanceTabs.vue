<script setup lang="ts">
import { Monitor, Moon, Sun } from '@lucide/vue';
import { computed } from 'vue';
import { useAppearance } from '@/composables/useAppearance';
import { t } from '@/lib/i18n';

const { appearance, updateAppearance } = useAppearance();

const tabs = computed(
    () =>
        [
            {
                value: 'light',
                Icon: Sun,
                label: t('settings.appearance.light'),
            },
            { value: 'dark', Icon: Moon, label: t('settings.appearance.dark') },
            {
                value: 'system',
                Icon: Monitor,
                label: t('settings.appearance.system'),
            },
        ] as const,
);
</script>

<template>
    <div class="bg-muted inline-flex gap-1 rounded-lg p-1">
        <button
            v-for="{ value, Icon, label } in tabs"
            :key="value"
            @click="updateAppearance(value)"
            :class="[
                'flex items-center rounded-md px-3.5 py-1.5 transition-colors',
                appearance === value
                    ? 'bg-card dark:bg-accent text-foreground shadow-xs'
                    : 'text-muted-foreground hover:bg-accent hover:text-accent-foreground',
            ]"
        >
            <component :is="Icon" class="-ms-1 h-4 w-4" />
            <span class="ms-1.5 text-sm">{{ label }}</span>
        </button>
    </div>
</template>
