<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import LanguageSwitch from '@/components/LanguageSwitch.vue';
import ThemeToggle from '@/components/ThemeToggle.vue';
import { Badge } from '@/components/ui/badge';
import { SidebarTrigger } from '@/components/ui/sidebar';
import type { BreadcrumbItem } from '@/types';

withDefaults(
    defineProps<{
        breadcrumbs?: BreadcrumbItem[];
    }>(),
    {
        breadcrumbs: () => [],
    },
);

const auth = computed(() => usePage().props.auth);
</script>

<template>
    <header
        class="border-sidebar-border/70 flex h-16 shrink-0 items-center gap-2 border-b px-6 transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 md:px-4"
    >
        <div class="flex items-center gap-2">
            <SidebarTrigger class="-ml-1" />
            <template v-if="breadcrumbs && breadcrumbs.length > 0">
                <Breadcrumbs :breadcrumbs="breadcrumbs" />
            </template>
        </div>
        <div class="ms-auto flex items-center gap-1">
            <span class="hidden text-sm text-muted-foreground md:inline">{{ auth.user?.name }}</span>
            <Badge v-for="dept in auth.departments" :key="dept.id" variant="secondary">{{ dept.name }}</Badge>
            <ThemeToggle />
            <LanguageSwitch />
        </div>
    </header>
</template>
