<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import GlobalSearch from '@/components/GlobalSearch.vue';
import LanguageSwitch from '@/components/LanguageSwitch.vue';
import ThemeToggle from '@/components/ThemeToggle.vue';
import { Badge } from '@/components/ui/badge';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { can } from '@/lib/can';
import type { BreadcrumbItem } from '@/types';

const props = withDefaults(
    defineProps<{
        breadcrumbs?: BreadcrumbItem[];
    }>(),
    {
        breadcrumbs: () => [],
    },
);

const auth = computed(() => usePage().props.auth);
const canSearch = computed(() => can('inquiry.view'));

/**
 * Below `md` the trail is replaced by its last leg: on a 360px screen a four-level
 * breadcrumb either wraps into three lines or shoves the search box off the edge,
 * and the leaf is the only part a user needs to orient themselves anyway.
 */
const currentPage = computed(
    () => props.breadcrumbs[props.breadcrumbs.length - 1]?.title ?? '',
);
</script>

<template>
    <header
        class="bg-background/95 border-sidebar-border/70 supports-[backdrop-filter]:bg-background/75 sticky top-0 z-30 flex h-14 shrink-0 items-center gap-2 border-b px-2 backdrop-blur sm:h-16 sm:gap-3 sm:px-4"
    >
        <SidebarTrigger class="-ms-1 size-9 shrink-0" />

        <Breadcrumbs
            v-if="breadcrumbs.length > 0"
            :breadcrumbs="breadcrumbs"
            class="hidden min-w-0 md:flex"
        />
        <span
            v-if="currentPage"
            class="min-w-0 truncate text-sm font-semibold md:hidden"
            >{{ currentPage }}</span
        >

        <div class="ms-auto flex shrink-0 items-center gap-1 sm:gap-2">
            <GlobalSearch v-if="canSearch" class="min-w-0" />
            <span
                class="text-muted-foreground hidden max-w-40 truncate text-sm lg:inline"
                >{{ auth.user?.name }}</span
            >
            <div class="hidden items-center gap-1 xl:flex">
                <Badge
                    v-for="dept in auth.departments"
                    :key="dept.id"
                    variant="secondary"
                    >{{ dept.name }}</Badge
                >
            </div>
            <ThemeToggle />
            <LanguageSwitch />
        </div>
    </header>
</template>
