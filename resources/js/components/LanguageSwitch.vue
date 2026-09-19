<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { Languages } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { switchLocale, t } from '@/lib/i18n';

const page = usePage();
const locales = computed(
    () => page.props.locales as Record<string, { name: string; dir: string }>,
);
const current = computed(() => page.props.locale as string);
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <Button variant="ghost" size="sm" :aria-label="t('app.language')">
                <Languages class="size-4" />
                <span class="text-xs">{{ locales[current]?.name }}</span>
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end">
            <DropdownMenuItem
                v-for="(meta, code) in locales"
                :key="code"
                :disabled="code === current"
                @select="switchLocale(String(code))"
            >
                {{ meta.name }}
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
